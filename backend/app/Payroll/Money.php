<?php

namespace App\Payroll;

use InvalidArgumentException;

/**
 * Integer minor-unit arithmetic. Amounts never pass through floats; rounding is half away from zero.
 * USD, AED, CAD and MXN all use two decimal places.
 */
final class Money
{
    public const CURRENCIES = ['USD' => 2, 'AED' => 2, 'CAD' => 2, 'MXN' => 2];

    public static function decimals(string $currency): int
    {
        return self::CURRENCIES[$currency] ?? throw new InvalidArgumentException("Unsupported currency {$currency}.");
    }

    /** "1234.5" → 123450. Rejects more decimals than the currency allows. */
    public static function parse(string|int $amount, string $currency): int
    {
        $d = self::decimals($currency);
        $s = trim((string) $amount);
        if (! preg_match('/^(-)?(\d{1,13})(?:\.(\d+))?$/', $s, $m)) {
            throw new InvalidArgumentException('Invalid amount.');
        }
        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $d) {
            throw new InvalidArgumentException("At most {$d} decimal places are allowed.");
        }
        $minor = (int) $m[2] * 10 ** $d + (int) str_pad($fraction, $d, '0');

        return ($m[1] ?? '') === '-' ? -$minor : $minor;
    }

    /** 123450 → "1234.50" (machine format, used in APIs and CSV). */
    public static function toDecimal(int $minor, string $currency): string
    {
        $d = self::decimals($currency);
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 10 ** $d).($d ? '.'.str_pad((string) ($abs % 10 ** $d), $d, '0', STR_PAD_LEFT) : '');
    }

    /** 123450 → "1,234.50" (display format for payslips). */
    public static function format(int $minor, string $currency): string
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim(self::toDecimal($minor, $currency), '-')), 2, '');

        return ($minor < 0 ? '-' : '').number_format((int) $whole).($fraction !== '' ? ".{$fraction}" : '');
    }

    /** round(a × num / den), half away from zero, exact for the magnitudes payroll uses. */
    public static function mulDiv(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }
        $product = $amount * $numerator;
        $sign = $product < 0 ? -1 : 1;
        $abs = abs($product);
        $q = intdiv($abs, $denominator);
        if (($abs % $denominator) * 2 >= $denominator) {
            $q++;
        }

        return $sign * $q;
    }

    /** Percentage expressed in basis points (1% = 100). */
    public static function percent(int $amount, int $basisPoints): int
    {
        return self::mulDiv($amount, $basisPoints, 10_000);
    }
}
