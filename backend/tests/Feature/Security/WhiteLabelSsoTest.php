<?php

namespace Tests\Feature\Security;

use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Security\RoleCatalog;
use App\Security\Sso\OidcClient;
use App\Security\Sso\OutboundHttp;
use App\Security\Sso\SsoError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use OpenSSLAsymmetricKey;

class WhiteLabelSsoTest extends SecurityTestCase
{
    private const ISSUER = 'https://idp.example.com';

    private Tenant $tenant;

    private User $owner;

    private OpenSSLAsymmetricKey $key;

    private ?string $idToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        RateLimiter::clear('login');
        $this->tenant = $this->makeTenant(['slug' => 'acme']);
        $this->owner = $this->makeUser($this->tenant, RoleCatalog::ORGANIZATION_OWNER, attributes: ['email' => 'owner@acme.test']);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->app->instance(OutboundHttp::class, new OutboundHttp(fn () => ['93.184.216.34']));

        $rsa = openssl_pkey_get_details($this->key)['rsa'];
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token', 'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            self::ISSUER.'/jwks' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'n' => OidcClient::b64url($rsa['n']), 'e' => OidcClient::b64url($rsa['e'])]]]),
            self::ISSUER.'/token' => fn () => Http::response(['id_token' => $this->idToken, 'token_type' => 'Bearer']),
        ]);
    }

    public function test_organization_manages_branding_and_the_sign_in_page_shows_it(): void
    {
        $this->apiAs($this->owner, 'PATCH', '/api/v1/organization/branding', ['brand_name' => 'Acme People', 'brand_color' => '#ffcc00'])
            ->assertUnprocessable()->assertJsonValidationErrors('brand_color');
        $this->apiAs($this->owner, 'PATCH', '/api/v1/organization/branding', ['brand_name' => 'Acme People', 'brand_color' => '#0b5394'])
            ->assertOk()->assertJsonPath('data.branding.name', 'Acme People')->assertJsonPath('data.branding.color', '#0B5394');

        $this->apiAs($this->owner, 'POST', '/api/v1/organization/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
            ->assertUnprocessable();
        $logo = $this->apiAs($this->owner, 'POST', '/api/v1/organization/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 60)])
            ->assertOk()->json('data.branding.logo_url');
        $this->assertStringStartsWith('/api/v1/public/organizations/acme/logo?v=', $logo);

        $employee = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $this->apiAs($employee, 'PATCH', '/api/v1/organization/branding', ['brand_name' => 'Hijack'])->assertForbidden();
        $this->apiAs($employee, 'POST', '/api/v1/organization/logo', ['logo' => UploadedFile::fake()->image('x.png')])->assertForbidden();

        $this->getJson('/api/v1/public/organizations/acme')->assertOk()
            ->assertJsonPath('data.branding.name', 'Acme People')->assertJsonPath('data.sso.enabled', false)->assertJsonMissingPath('data.id');
        $this->get($logo)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/public/organizations/nope')->assertNotFound();

        $this->apiAs($this->owner, 'DELETE', '/api/v1/organization/logo')->assertOk()->assertJsonPath('data.branding.logo_url', null);
        $this->get('/api/v1/public/organizations/acme/logo')->assertNotFound();
    }

    public function test_platform_staff_create_a_branded_organization_with_its_own_mfa_policy(): void
    {
        $staff = $this->makeUser(null, RoleCatalog::PLATFORM_OWNER);
        $this->apiAs($staff, 'POST', '/api/v1/platform/tenants', [
            'name' => 'Globex', 'slug' => 'globex', 'country_code' => 'AE', 'owner_name' => 'Gina', 'owner_email' => 'gina@globex.test',
            'brand_name' => 'Globex HR', 'brand_color' => '#ffffff',
        ])->assertUnprocessable()->assertJsonValidationErrors('brand_color');

        $id = $this->apiAs($staff, 'POST', '/api/v1/platform/tenants', [
            'name' => 'Globex', 'slug' => 'globex', 'country_code' => 'AE', 'owner_name' => 'Gina', 'owner_email' => 'gina@globex.test',
            'brand_name' => 'Globex HR', 'brand_color' => '#7a1f5c', 'mfa_required' => true,
        ])->assertCreated()->assertJsonPath('data.branding.name', 'Globex HR')->assertJsonPath('data.mfa_required', true)->json('data.id');
        $this->apiAs($staff, 'POST', "/api/v1/platform/tenants/$id/logo", ['logo' => UploadedFile::fake()->image('logo.jpg')])
            ->assertOk()->assertJsonPath('data.branding.logo_url', fn ($url) => str_starts_with($url, '/api/v1/public/organizations/globex/logo'));

        $this->apiAs($this->owner, 'POST', "/api/v1/platform/tenants/$id/logo", ['logo' => UploadedFile::fake()->image('logo.jpg')])->assertForbidden();
        $defaults = $this->apiAs($staff, 'POST', '/api/v1/platform/tenants', ['name' => 'Plain', 'slug' => 'plain', 'country_code' => 'US', 'owner_name' => 'P', 'owner_email' => 'p@plain.test']);
        $defaults->assertCreated()->assertJsonPath('data.mfa_required', false)->assertJsonPath('data.branding.name', null);
    }

    public function test_sso_settings_keep_the_secret_write_only_and_check_the_provider(): void
    {
        $hr = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN);
        $this->apiAs($hr, 'GET', '/api/v1/organization/sso')->assertForbidden();
        $this->apiAs($this->owner, 'PUT', '/api/v1/organization/sso', $this->ssoPayload(['issuer' => 'http://idp.example.com']))
            ->assertUnprocessable()->assertJsonValidationErrors('issuer');
        $this->apiAs($this->owner, 'PUT', '/api/v1/organization/sso', $this->ssoPayload(['enabled' => false, 'enforced' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('enforced');

        $saved = $this->apiAs($this->owner, 'PUT', '/api/v1/organization/sso', $this->ssoPayload())->assertOk();
        $saved->assertJsonPath('data.connection.has_client_secret', true)->assertJsonPath('data.redirect_uri', 'http://localhost/api/v1/auth/sso/callback');
        $this->assertStringNotContainsString('top-secret', $saved->getContent());

        $this->apiAs($this->owner, 'PUT', '/api/v1/organization/sso', $this->ssoPayload(['client_secret' => null, 'version' => 99]))->assertStatus(409);
        $this->apiAs($this->owner, 'PUT', '/api/v1/organization/sso', $this->ssoPayload(['client_secret' => null, 'version' => 1, 'enforced' => true]))
            ->assertOk()->assertJsonPath('data.connection.version', 2)->assertJsonPath('data.connection.enforced', true);
        $this->assertSame('top-secret', $this->tenant->ssoConnection()->first()->client_secret);
        $this->getJson('/api/v1/public/organizations/acme')->assertJsonPath('data.sso', ['enabled' => true, 'enforced' => true]);
    }

    public function test_sso_signs_in_an_existing_user_and_skips_wingora_mfa(): void
    {
        $this->tenant->forceFill(['mfa_required' => true])->save();
        $hr = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN, mfa: false, attributes: ['email' => 'hr@acme.test']);
        $this->enableSso();

        $pending = $this->startSso();
        $this->idToken = $this->jwt(['sub' => 'u1', 'email' => 'HR@acme.test', 'nonce' => $pending['nonce']]);
        $this->withSession(['sso.pending' => $pending])->get('/api/v1/auth/sso/callback?code=abc&state='.$pending['state'])
            ->assertRedirect('http://localhost:5173/sso/complete');
        $this->assertAuthenticatedAs($hr, 'web');
        Http::assertSent(fn ($r) => $r->url() === self::ISSUER.'/token' && $r['code_verifier'] === $pending['verifier'] && $r->hasHeader('Authorization'));

        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('mfa_enrollment_required', false)->assertJsonPath('signed_in_with_sso', true);
        $this->spa()->getJson('/api/v1/employees')->assertOk();
    }

    public function test_sso_rejects_forged_or_mismatched_responses(): void
    {
        $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE, attributes: ['email' => 'emp@acme.test']);
        $other = $this->makeTenant();
        $this->makeUser($other, RoleCatalog::EMPLOYEE, attributes: ['email' => 'outsider@acme.test']);
        $this->enableSso();
        $forger = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $cases = [
            'wrong state' => fn ($p) => [['email' => 'emp@acme.test', 'nonce' => $p['nonce']], 'state' => 'forged'],
            'wrong nonce' => fn ($p) => [['email' => 'emp@acme.test', 'nonce' => 'other']],
            'other audience' => fn ($p) => [['email' => 'emp@acme.test', 'nonce' => $p['nonce'], 'aud' => 'someone-else']],
            'expired' => fn ($p) => [['email' => 'emp@acme.test', 'nonce' => $p['nonce'], 'exp' => time() - 3600]],
            'forged signature' => fn ($p) => [['email' => 'emp@acme.test', 'nonce' => $p['nonce']], 'key' => $forger],
            'unverified email' => fn ($p) => [['email' => 'emp@acme.test', 'email_verified' => false, 'nonce' => $p['nonce']]],
            'domain not allowed' => fn ($p) => [['email' => 'emp@evil.test', 'nonce' => $p['nonce']]],
            'other organization' => fn ($p) => [['email' => 'outsider@acme.test', 'nonce' => $p['nonce']]],
        ];
        foreach ($cases as $name => $case) {
            $pending = $this->startSso();
            $spec = $case($pending);
            $this->idToken = $this->jwt($spec[0], $spec['key'] ?? null);
            $this->withSession(['sso.pending' => $pending])->get('/api/v1/auth/sso/callback?code=abc&state='.($spec['state'] ?? $pending['state']))
                ->assertRedirect('http://localhost:5173/login/acme?sso_error=1');
            $this->assertGuest('web');
        }
        $this->assertSame(count($cases), SecurityEvent::query()->withoutGlobalScopes()->where('type', 'auth.sso_failed')->count());
    }

    public function test_enforced_sso_blocks_passwords_except_for_the_owner(): void
    {
        $hr = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN);
        $this->enableSso(enforced: true);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $hr->email, 'password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('sso');
        $this->postJson('/api/v1/auth/tokens', ['email' => $hr->email, 'password' => self::PASSWORD, 'device_name' => 'cli'])->assertUnprocessable();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD])->assertStatus(202);
    }

    public function test_provider_calls_only_reach_public_https_addresses(): void
    {
        foreach ([['http://idp.example.com/x', ['93.184.216.34']], ['https://idp.example.com/x', ['10.0.0.5']], ['https://idp.example.com/x', ['169.254.169.254']], ['https://idp.example.com/x', []]] as [$url, $ips]) {
            try {
                (new OutboundHttp(fn () => $ips))->to($url);
                $this->fail("$url should be refused");
            } catch (SsoError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->get('/api/v1/auth/sso/acme/redirect')->assertRedirect('http://localhost:5173/login/acme?sso_error=1');
    }

    private function ssoPayload(array $overrides = []): array
    {
        return $overrides + ['issuer' => self::ISSUER, 'client_id' => 'wingora-client', 'client_secret' => 'top-secret', 'allowed_domains' => ['acme.test'], 'enabled' => true, 'enforced' => false];
    }

    private function enableSso(bool $enforced = false): void
    {
        $this->apiAs($this->owner, 'PUT', '/api/v1/organization/sso', $this->ssoPayload(['enforced' => $enforced]))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function startSso(): array
    {
        $response = $this->get('/api/v1/auth/sso/acme/redirect');
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);

        return session('sso.pending');
    }

    private function jwt(array $claims, ?OpenSSLAsymmetricKey $key = null): string
    {
        $claims += ['iss' => self::ISSUER, 'aud' => 'wingora-client', 'exp' => time() + 300, 'iat' => time()];
        $segments = OidcClient::b64url(json_encode(['alg' => 'RS256', 'kid' => 'k1'])).'.'.OidcClient::b64url(json_encode($claims));
        openssl_sign($segments, $signature, $key ?? $this->key, OPENSSL_ALGO_SHA256);

        return $segments.'.'.OidcClient::b64url($signature);
    }
}
