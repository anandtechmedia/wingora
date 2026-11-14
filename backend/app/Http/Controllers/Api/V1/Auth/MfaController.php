<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Security\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MfaController extends Controller
{
    public function __construct(private readonly TwoFactor $mfa) {}

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasMfaEnabled(), 409, 'MFA is already enabled.');

        $secret = $this->mfa->generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null, 'mfa_last_timestep' => null])->saveQuietly();
        SecurityEvent::record('auth.mfa_setup_started', 'info', $user);

        $url = $this->mfa->otpauthUrl($user, $secret);

        return response()->json(['secret' => $secret, 'otpauth_url' => $url, 'qr_code' => $this->mfa->qrCodeDataUri($url)]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        abort_if($user->hasMfaEnabled(), 409, 'MFA is already enabled.');

        if (! $this->mfa->verifyCode($user, $data['code'])) {
            SecurityEvent::record('auth.mfa_failed', 'warning', $user, ['stage' => 'confirm']);
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        $user->forceFill(['mfa_confirmed_at' => now()])->saveQuietly();
        $codes = $this->mfa->issueRecoveryCodes($user);
        SecurityEvent::record('auth.mfa_enabled', 'warning', $user);

        return response()->json(['recovery_codes' => $codes]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        abort_unless($user->hasMfaEnabled(), 409, 'MFA is not enabled.');

        if (! $this->mfa->verifyCode($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        SecurityEvent::record('auth.mfa_recovery_codes_regenerated', 'warning', $user);

        return response()->json(['recovery_codes' => $this->mfa->issueRecoveryCodes($user)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->requiresMfa(), 403, 'MFA is mandatory for your role and cannot be disabled.');

        $data = $request->validate(['password' => ['required', 'string', 'max:128'], 'code' => ['required', 'string', 'max:10']]);
        if (! Hash::check($data['password'], $user->password) || ! $this->mfa->verifyCode($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The password or authentication code is invalid.']);
        }

        $user->forceFill(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_confirmed_at' => null, 'mfa_last_timestep' => null])->saveQuietly();
        SecurityEvent::record('auth.mfa_disabled', 'warning', $user);

        return response()->json(null, 204);
    }
}
