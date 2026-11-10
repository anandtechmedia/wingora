<?php

namespace App\Support;

final class Csv
{
    /** Neutralises spreadsheet formula injection by prefixing risky leading characters. */
    public static function cell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
