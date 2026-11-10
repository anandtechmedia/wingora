<?php

namespace App\Audit;

/** Removes secrets and unnecessary sensitive data from audit payloads, recursively. */
final class Redactor
{
    public const MASK = '[REDACTED]';

    private const KEYS = [
        'password', 'password_confirmation', 'current_password', 'remember_token', 'secret', 'client_secret',
        'mfa_secret', 'mfa_recovery_codes', 'recovery_code', 'recovery_codes', 'code', 'otp', 'token', 'access_token',
        'refresh_token', 'plain_text_token', 'api_key', 'authorization', 'cookie', 'national_id', 'bank_account_number',
        'date_of_birth', 'tax_identifier', 'registration_number', 'ssn', 'sin', 'passport_number', 'document_number',
        'file_contents', 'content',
    ];

    private const MAX_STRING = 1000;

    public static function clean(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $clean[$key] = match (true) {
                is_string($key) && self::isSensitiveKey($key) => self::MASK,
                is_array($value) => self::clean($value),
                is_string($value) && self::looksLikeCredential($value) => self::MASK,
                is_string($value) && mb_strlen($value) > self::MAX_STRING => mb_substr($value, 0, self::MAX_STRING).'…',
                default => $value,
            };
        }

        return $clean;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, self::KEYS, true) || (bool) preg_match('/(password|secret|_token$|api_key)/', $key);
    }

    private static function looksLikeCredential(string $value): bool
    {
        $prefix = preg_quote((string) config('sanctum.token_prefix', 'wgr_'), '/');

        return (bool) preg_match('/^(Bearer\s+\S+|'.$prefix.'\S{20,}|\d+\|\S{20,})$/i', trim($value));
    }
}
