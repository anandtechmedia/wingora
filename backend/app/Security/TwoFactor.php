<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactor
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl((string) config('security.mfa.issuer'), $user->email, $secret);
    }

    /** Verifies a TOTP code and rejects reuse of the same or an older time step. */
    public function verifyCode(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! $user->mfa_secret || ! preg_match('/^\d{6}$/', (string) $code)) {
            return false;
        }

        $timestep = $this->engine->verifyKeyNewer($user->mfa_secret, $code, $user->mfa_last_timestep, (int) config('security.mfa.window'));
        if ($timestep === false) {
            return false;
        }

        $user->forceFill(['mfa_last_timestep' => $timestep === true ? $this->engine->getTimestamp() : $timestep])->saveQuietly();

        return true;
    }

    /** Recovery codes are stored hashed and consumed on use. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));
        $hashes = $user->mfa_recovery_codes ?? [];

        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['mfa_recovery_codes' => array_values($hashes)])->saveQuietly();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> plain codes, shown to the user exactly once */
    public function issueRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, (int) config('security.mfa.recovery_codes')))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user->forceFill(['mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->saveQuietly();

        return $codes;
    }

    public function verify(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if ($code !== null && $code !== '') {
            return $this->verifyCode($user, $code);
        }

        return $recoveryCode !== null && $recoveryCode !== '' && $this->useRecoveryCode($user, $recoveryCode);
    }
}
