<?php

namespace App\Security;

use App\Models\Concerns\TenantScope;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginService
{
    private static ?string $dummyHash = null;

    /**
     * Verifies credentials with lockout and uniform responses: unknown email,
     * wrong password, locked or disabled account all produce the same error.
     */
    public function attempt(string $email, string $password): User
    {
        $user = User::query()->withoutGlobalScope(TenantScope::class)->with('tenant')
            ->where('email', Str::lower(trim($email)))->first();

        $valid = Hash::check($password, $user?->password ?? (self::$dummyHash ??= Hash::make(Str::random(40))));

        if ($user?->isLocked()) {
            SecurityEvent::record('auth.login_blocked_locked', 'warning', $user);
            $this->fail();
        }

        if (! $user || ! $valid) {
            $user ? $this->registerFailure($user) : SecurityEvent::record('auth.login_failed', 'info', null, ['reason' => 'unknown_email']);
            $this->fail();
        }

        if (! $user->isActive() || ($user->tenant_id !== null && ! $user->tenant?->isActive()) || ($user->tenant_id === null && ! $user->isPlatformStaff())) {
            SecurityEvent::record('auth.login_failed', 'warning', $user, ['reason' => 'inactive']);
            $this->fail();
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->saveQuietly();
        }

        return $user;
    }

    public function recordSuccess(User $user, Request $request, string $channel): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        SecurityEvent::record('auth.login_succeeded', 'info', $user, ['channel' => $channel]);
    }

    /** Ends every session and token of a user (password change/reset, disablement). */
    public function revokeEverything(User $user, ?string $exceptSessionId = null): void
    {
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)
                ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))->delete();
        }

        SecurityEvent::record('auth.credentials_revoked', 'warning', $user);
    }

    private function registerFailure(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $max = (int) config('security.login.max_attempts');

        if ($attempts >= $max) {
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes((int) config('security.login.lockout_minutes'))])->saveQuietly();
            SecurityEvent::record('auth.account_locked', 'warning', $user);

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->saveQuietly();
        SecurityEvent::record('auth.login_failed', 'info', $user, ['reason' => 'bad_password', 'attempt' => $attempts]);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
    }
}
