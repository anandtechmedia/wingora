<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Concerns\TenantScope;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Security\LoginService;
use App\Security\Sso\OidcClient;
use App\Security\Sso\SsoError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Browser redirects for OIDC single sign-on. These are top-level navigations (the identity provider sends the
 * browser back here), so they use the `web` session rather than Sanctum's same-origin SPA detection.
 * Users are matched to an existing account in that organization by email; SSO never creates accounts.
 */
class SsoController extends Controller
{
    private const PENDING_SECONDS = 600;

    public function __construct(private readonly OidcClient $oidc, private readonly LoginService $logins) {}

    public static function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/auth/sso/callback';
    }

    public function redirect(Request $request, string $slug): RedirectResponse
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();
        $connection = $tenant?->ssoConnection;
        if (! $tenant?->isActive() || ! $connection?->enabled) {
            return $this->fail($slug, 'not_enabled', $tenant);
        }

        $pending = ['tenant_id' => $tenant->id, 'slug' => $slug, 'state' => Str::random(40), 'nonce' => Str::random(40), 'verifier' => Str::random(64), 'at' => now()->getTimestamp()];
        try {
            $url = $this->oidc->authorizationUrl($connection, self::callbackUrl(), $pending['state'], $pending['nonce'], $pending['verifier']);
        } catch (SsoError $e) {
            return $this->fail($slug, $e->reason, $tenant);
        }
        $request->session()->put('sso.pending', $pending);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('sso.pending');
        $slug = is_array($pending) ? $pending['slug'] : null;
        $tenant = is_array($pending) ? Tenant::query()->find($pending['tenant_id']) : null;
        $state = $request->query('state');

        if (! $tenant || ! is_string($state) || ! hash_equals($pending['state'], $state) || now()->getTimestamp() - $pending['at'] > self::PENDING_SECONDS) {
            return $this->fail($slug, 'state_invalid', $tenant);
        }
        $connection = $tenant->ssoConnection;
        if (! $tenant->isActive() || ! $connection?->enabled) {
            return $this->fail($slug, 'not_enabled', $tenant);
        }
        $code = $request->query('code');
        if ($request->query('error') !== null || ! is_string($code) || $code === '') {
            return $this->fail($slug, 'provider_denied', $tenant);
        }

        try {
            $claims = $this->oidc->exchange($connection, $code, $pending['verifier'], self::callbackUrl(), $pending['nonce']);
        } catch (SsoError $e) {
            return $this->fail($slug, $e->reason, $tenant);
        }

        $email = collect([$claims['email'] ?? null, $claims['preferred_username'] ?? null])
            ->first(fn ($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL));
        if (! $email || in_array($claims['email_verified'] ?? true, [false, 'false'], true) || ! $connection->allowsEmail($email)) {
            return $this->fail($slug, 'email_not_allowed', $tenant);
        }

        $user = User::query()->withoutGlobalScope(TenantScope::class)->with('tenant')
            ->where('tenant_id', $tenant->id)->where('email', Str::lower($email))->first();
        if (! $user || ! $user->isActive() || $user->isLocked()) {
            return $this->fail($slug, 'no_active_account', $tenant, $user);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(['auth.started_at' => now()->getTimestamp(), 'auth.sso' => $user->id]);
        $this->logins->recordSuccess($user, $request, 'sso');

        return redirect()->away(rtrim((string) config('security.frontend_url'), '/').'/sso/complete');
    }

    private function fail(?string $slug, string $reason, ?Tenant $tenant, ?User $user = null): RedirectResponse
    {
        SecurityEvent::record('auth.sso_failed', 'warning', $user, ['reason' => $reason], $tenant?->id);
        $path = $slug && preg_match('/^[a-z0-9-]{1,63}$/', $slug) ? '/login/'.$slug : '/login';

        return redirect()->away(rtrim((string) config('security.frontend_url'), '/').$path.'?sso_error=1');
    }
}
