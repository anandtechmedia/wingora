<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Concerns\TenantScope;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Security\LoginService;
use App\Security\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Cookie-session login for the first-party web app (Sanctum SPA mode, CSRF protected). */
class SessionController extends Controller
{
    use Concerns;

    public function __construct(private readonly LoginService $logins, private readonly TwoFactor $mfa) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 400, 'Browser login is only available to first-party origins. Use /api/v1/auth/tokens.');

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $user = $this->logins->attempt($data['email'], $data['password']);

        if ($user->hasMfaEnabled()) {
            $request->session()->regenerate();
            $request->session()->put(['auth.pending_user_id' => $user->id, 'auth.pending_at' => now()->getTimestamp()]);

            return response()->json(['mfa_required' => true], 202);
        }

        return $this->complete($request, $user);
    }

    public function mfa(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 400);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:10', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $pendingId = $request->session()->get('auth.pending_user_id');
        $pendingAt = (int) $request->session()->get('auth.pending_at', 0);
        $user = $pendingId && now()->getTimestamp() - $pendingAt <= (int) config('security.mfa.pending_login_seconds')
            ? User::query()->withoutGlobalScope(TenantScope::class)->find($pendingId)
            : null;

        if (! $user || ! $this->mfa->verify($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            SecurityEvent::record('auth.mfa_failed', 'warning', $user);
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid or has expired.']);
        }

        if (! empty($data['recovery_code'])) {
            SecurityEvent::record('auth.mfa_recovery_code_used', 'warning', $user);
        }

        $request->session()->forget(['auth.pending_user_id', 'auth.pending_at']);

        return $this->complete($request, $user);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        SecurityEvent::record('auth.logout', 'info', $user);

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    private function complete(Request $request, User $user): JsonResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.started_at', now()->getTimestamp());
        $this->logins->recordSuccess($user, $request, 'session');

        return response()->json($this->sessionPayload($user));
    }
}
