<?php

namespace Tests\Feature\Security;

use App\Models\Tenant;
use App\Models\User;
use App\Security\RoleCatalog;
use App\Support\Tenancy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

class StrictCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

class AuthenticationTest extends SecurityTestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        RateLimiter::clear('login');
    }

    public function test_spa_login_creates_session_and_logout_ends_it(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('user.id', $user->public_id)->assertJsonMissingPath('user.password');
        $this->assertAuthenticatedAs($user, 'web');
        $this->spa()->getJson('/api/v1/auth/me')->assertOk();

        $this->spa()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_browser_login_requires_first_party_origin(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(400);
    }

    public function test_csrf_token_is_required_for_cookie_authenticated_writes(): void
    {
        config(['sanctum.middleware.validate_csrf_token' => StrictCsrf::class]);
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(419);

        $this->spa()->withSession(['_token' => 'known-token'])->withHeader('X-CSRF-TOKEN', 'known-token')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    public function test_wrong_password_and_unknown_email_give_identical_errors(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $a = $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()->json('errors');
        $b = $this->spa()->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertUnprocessable()->json('errors');
        $this->assertSame($a, $b);
    }

    public function test_account_locks_after_repeated_failures(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'x'], ['REMOTE_ADDR' => "10.0.0.$i"]);
        }
        RateLimiter::clear('login');

        $this->assertTrue($user->fresh()->isLocked());
        $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => self::PASSWORD, 'device_name' => 'x'], ['REMOTE_ADDR' => '10.0.1.1'])->assertUnprocessable();
        $this->assertDatabaseHas('security_events', ['type' => 'auth.account_locked', 'user_id' => $user->id]);
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        $responses = collect(range(1, 7))->map(fn () => $this->postJson('/api/v1/auth/tokens', ['email' => 'x@example.com', 'password' => 'bad', 'device_name' => 'x'])->status());
        $this->assertContains(429, $responses->all());
    }

    public function test_mfa_login_flow_and_replay_protection(): void
    {
        $hr = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $hr->email, 'password' => self::PASSWORD])->assertStatus(202)->assertJsonPath('mfa_required', true);
        $this->assertGuest('web');

        $this->spa()->postJson('/api/v1/auth/login/mfa', ['code' => '000000'])->assertUnprocessable();
        $code = $this->totp($hr);
        $this->spa()->postJson('/api/v1/auth/login/mfa', ['code' => $code])->assertOk();
        $this->assertAuthenticatedAs($hr, 'web');

        // The same code cannot be replayed for a second login.
        $this->spa()->postJson('/api/v1/auth/logout');
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $hr->email, 'password' => self::PASSWORD])->assertStatus(202);
        $this->spa()->postJson('/api/v1/auth/login/mfa', ['code' => $code])->assertUnprocessable();
    }

    public function test_mfa_challenge_requires_a_pending_password_step(): void
    {
        $this->spa()->postJson('/api/v1/auth/login/mfa', ['code' => '123456'])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_mfa_enrolment_issues_hashed_single_use_recovery_codes(): void
    {
        $hr = $this->makeUser($this->tenant, RoleCatalog::HR_MANAGER, mfa: false);
        $token = $this->tokenFor($hr);

        $setup = $this->apiWithToken($token, 'POST', '/api/v1/auth/mfa/setup')->assertOk();
        $secret = $setup->json('secret');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $setup->json('qr_code'));
        $this->assertStringContainsString('<svg', base64_decode(substr($setup->json('qr_code'), 26)));
        $this->assertNotSame($secret, \DB::table('users')->where('id', $hr->id)->value('mfa_secret'));

        $codes = $this->apiWithToken($token, 'POST', '/api/v1/auth/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()->json('recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertStringNotContainsString($codes[0], (string) \DB::table('users')->where('id', $hr->id)->value('mfa_recovery_codes'));

        $this->apiWithToken($token, 'DELETE', '/api/v1/auth/mfa', ['password' => self::PASSWORD, 'code' => '000000'])->assertForbidden();

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $hr->email, 'password' => self::PASSWORD])->assertStatus(202);
        $this->spa()->postJson('/api/v1/auth/login/mfa', ['recovery_code' => $codes[0]])->assertOk();
        $this->spa()->postJson('/api/v1/auth/logout');
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $hr->email, 'password' => self::PASSWORD])->assertStatus(202);
        $this->spa()->postJson('/api/v1/auth/login/mfa', ['recovery_code' => $codes[0]])->assertUnprocessable();
    }

    public function test_password_reset_is_enumeration_safe_single_use_and_revokes_sessions(): void
    {
        Notification::fake();
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $oldToken = $this->tokenFor($user);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertStatus(202)->json();
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.com'])->assertStatus(202)->json();
        $this->assertSame($known, $unknown);

        $resetToken = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$resetToken, $user) {
            $resetToken = $n->token;

            return str_starts_with($n->toMail($user)->actionUrl, 'http://localhost:5173/reset-password?token=');
        });

        $payload = ['token' => $resetToken, 'email' => $user->email, 'password' => 'Brand-New-Passw0rd!', 'password_confirmation' => 'Brand-New-Passw0rd!'];
        $this->postJson('/api/v1/auth/password/reset', ['password' => 'weak', 'password_confirmation' => 'weak'] + $payload)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertUnprocessable();

        $this->apiWithToken($oldToken, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'Brand-New-Passw0rd!', 'device_name' => 'x'])->assertCreated();
    }

    public function test_session_cookie_is_http_only_and_same_site(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $cookie = collect($this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertTrue(config('session.encrypt'));
    }

    public function test_sessions_older_than_absolute_lifetime_are_terminated(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();

        $this->travel((int) config('security.session.absolute_lifetime_minutes') + 1)->minutes();
        $this->spa()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_disabled_users_and_suspended_tenants_cannot_log_in(): void
    {
        $disabled = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE, attributes: []);
        $disabled->forceFill(['status' => 'disabled'])->saveQuietly();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $disabled->email, 'password' => self::PASSWORD])->assertUnprocessable();

        $other = $this->makeTenant();
        $other->forceFill(['status' => 'suspended'])->save();
        $member = $this->makeUser($other, RoleCatalog::EMPLOYEE);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $member->email, 'password' => self::PASSWORD])->assertUnprocessable();
    }

    public function test_user_without_any_role_has_no_permissions(): void
    {
        $user = Tenancy::run($this->tenant, fn () => User::factory()->create());
        $this->assertSame([], $user->permissions());
        $this->apiAs($user, 'GET', '/api/v1/employees')->assertForbidden();
    }
}
