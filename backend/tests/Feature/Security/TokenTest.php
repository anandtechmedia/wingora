<?php

namespace Tests\Feature\Security;

use App\Models\Tenant;
use App\Security\RoleCatalog;
use Illuminate\Support\Facades\Date;

class TokenTest extends SecurityTestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
    }

    public function test_unauthenticated_api_requests_are_rejected(): void
    {
        foreach (['/api/v1/auth/me', '/api/v1/employees', '/api/v1/organization', '/api/v1/platform/tenants', '/api/v1/auth/tokens'] as $uri) {
            $this->getJson($uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('status', 401);
        }
        $this->get('/api/v1/employees')->assertUnauthorized();
    }

    public function test_malformed_and_unknown_tokens_are_rejected(): void
    {
        foreach (['garbage', '1|notarealtoken', 'wgr_'.str_repeat('a', 40), '999|'.str_repeat('x', 40)] as $token) {
            $this->apiWithToken($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        }
    }

    public function test_token_issue_use_and_revoke_cannot_be_reused(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);

        $token = $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => self::PASSWORD, 'device_name' => 'iPhone'])
            ->assertCreated()->assertJsonPath('abilities', ['api:read'])->json('token');
        $this->assertStringStartsWith('wgr_', explode('|', $token)[1]);

        $this->apiWithToken($token, 'GET', '/api/v1/auth/me')->assertOk();
        $this->apiWithToken($token, 'DELETE', '/api/v1/auth/tokens/current')->assertNoContent();
        $this->apiWithToken($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('security_events', ['type' => 'auth.token_revoked']);
    }

    public function test_revoke_all_tokens_invalidates_every_token(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $t1 = $this->tokenFor($user);
        $t2 = $this->tokenFor($user);

        $this->apiWithToken($t1, 'DELETE', '/api/v1/auth/tokens')->assertNoContent();
        $this->apiWithToken($t1, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->apiWithToken($t2, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_users_cannot_revoke_or_list_other_users_tokens(): void
    {
        $alice = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $bob = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $bobToken = $bob->createToken('bob', ['api:read'], now()->addHour());

        $this->apiAs($alice, 'DELETE', '/api/v1/auth/tokens/'.$bobToken->accessToken->id)->assertNotFound();
        $this->apiWithToken($bobToken->plainTextToken, 'GET', '/api/v1/auth/me')->assertOk();
        $listed = collect($this->apiAs($alice, 'GET', '/api/v1/auth/tokens')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($bobToken->accessToken->id, $listed);
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $token = $user->createToken('short', ['api:read'], now()->addMinutes(5))->plainTextToken;

        $this->apiWithToken($token, 'GET', '/api/v1/auth/me')->assertOk();
        Date::setTestNow(now()->addMinutes(6));
        $this->apiWithToken($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        Date::setTestNow();
    }

    public function test_tokens_without_expiry_still_hit_the_global_ceiling(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $token = $user->createToken('legacy', ['api:read'])->plainTextToken;

        Date::setTestNow(now()->addMinutes((int) config('sanctum.expiration') + 1));
        $this->apiWithToken($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        Date::setTestNow();
    }

    public function test_read_only_tokens_cannot_write(): void
    {
        $owner = $this->makeUser($this->tenant, RoleCatalog::ORGANIZATION_OWNER);

        $this->apiAs($owner, 'PATCH', '/api/v1/organization', ['name' => 'Renamed'], ['api:read'])->assertForbidden();
        $this->apiAs($owner, 'GET', '/api/v1/organization', [], ['api:read'])->assertOk();
        $this->apiAs($owner, 'PATCH', '/api/v1/organization', ['name' => 'Renamed'], ['api:write'])->assertOk();
    }

    public function test_password_change_revokes_all_tokens(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $old = $this->tokenFor($user);

        $this->apiWithToken($old, 'PUT', '/api/v1/auth/password', [
            'current_password' => self::PASSWORD,
            'password' => 'New-Very-Strong-Pass-1!',
            'password_confirmation' => 'New-Very-Strong-Pass-1!',
        ])->assertOk();

        $this->apiWithToken($old, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_disabled_user_tokens_stop_working(): void
    {
        $owner = $this->makeUser($this->tenant, RoleCatalog::ORGANIZATION_OWNER);
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $token = $this->tokenFor($user);

        $this->apiAs($owner, 'PATCH', "/api/v1/users/{$user->public_id}/status", ['status' => 'disabled'])->assertOk();
        $this->apiWithToken($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_privileged_token_issuance_requires_mfa(): void
    {
        $hr = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN);
        $payload = ['email' => $hr->email, 'password' => self::PASSWORD, 'device_name' => 'cli'];

        $this->postJson('/api/v1/auth/tokens', $payload)->assertUnprocessable()->assertJsonValidationErrors('mfa_code');
        $this->postJson('/api/v1/auth/tokens', $payload + ['mfa_code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/tokens', $payload + ['mfa_code' => $this->totp($hr)])->assertCreated();

        $unenrolled = $this->makeUser($this->tenant, RoleCatalog::PAYROLL_ADMIN, mfa: false);
        $this->postJson('/api/v1/auth/tokens', ['email' => $unenrolled->email, 'password' => self::PASSWORD, 'device_name' => 'cli'])->assertForbidden();
    }

    public function test_token_abilities_are_whitelisted(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => self::PASSWORD, 'device_name' => 'x', 'abilities' => ['*']])
            ->assertUnprocessable()->assertJsonValidationErrors('abilities.0');
    }
}
