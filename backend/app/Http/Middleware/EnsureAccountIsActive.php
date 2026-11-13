<?php

namespace App\Http\Middleware;

use App\Models\SecurityEvent;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/** Rejects disabled users, suspended tenants and over-age browser sessions on every request. */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            throw new AuthenticationException;
        }

        $reason = match (true) {
            ! $user->isActive() => 'user_inactive',
            $user->tenant_id !== null && ! $user->tenant?->isActive() => 'tenant_inactive',
            $user->tenant_id === null && ! $user->isPlatformStaff() => 'no_effective_role',
            default => null,
        };

        $isSession = $user->currentAccessToken() === null || $user->currentAccessToken() instanceof TransientToken;

        if ($reason === null && $isSession && $request->hasSession()) {
            $startedAt = (int) $request->session()->get('auth.started_at', 0);
            $maxAge = (int) config('security.session.absolute_lifetime_minutes') * 60;
            if ($startedAt === 0 || now()->getTimestamp() - $startedAt > $maxAge) {
                $reason = 'session_expired';
            }
        }

        if ($reason !== null) {
            SecurityEvent::record('auth.access_denied', 'warning', $user, ['reason' => $reason]);
            if ($isSession && $request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } else {
                $user->currentAccessToken()?->delete();
            }

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
