<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Security\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function __construct(private readonly LoginService $logins) {}

    /** Always answers the same way so the endpoint cannot be used to discover accounts. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $status = Password::sendResetLink(['email' => Str::lower($data['email'])]);
        SecurityEvent::record('auth.password_reset_requested', 'info', null, ['status' => $status]);

        return response()->json(['message' => 'If the account exists, a reset link has been sent.'], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            ['email' => Str::lower($data['email']), 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
                $this->logins->revokeEverything($user);
                SecurityEvent::record('auth.password_reset', 'warning', $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            SecurityEvent::record('auth.password_reset_failed', 'warning', null, ['status' => $status]);
            throw ValidationException::withMessages(['email' => 'This password reset link is invalid or has expired.']);
        }

        return response()->json(['message' => 'Password has been reset. Please sign in.']);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordRule::defaults()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            SecurityEvent::record('auth.password_change_failed', 'warning', $user);
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
        $this->logins->revokeEverything($user, $request->hasSession() ? $request->session()->getId() : null);
        SecurityEvent::record('auth.password_changed', 'warning', $user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['message' => 'Password updated. Other sessions and all API tokens were signed out.']);
    }
}
