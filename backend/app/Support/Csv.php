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

    /** @param iterable<array<int, mixed>> $rows Builds a UTF-8 (BOM) CSV string with every cell neutralised. */
    public static function build(iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, array_map(self::cell(...), $row), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF".$csv;
    }
}
