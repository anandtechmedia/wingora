<?php

namespace App\Payroll\Providers;

use App\Models\PayrollRun;
use App\Payroll\Money;
use App\Support\Csv;
use Illuminate\Validation\ValidationException;

/** Provider-neutral CSV exchange: exports the payroll register and imports `employee_number,code,name,kind,amount,reference` rows. */
final class GenericCsvProvider implements PayrollProvider
{
    public const COLUMNS = ['employee_number', 'code', 'name', 'kind', 'amount', 'reference'];

    public function key(): string
    {
        return 'generic_csv';
    }

    public function name(): string
    {
        return 'Generic CSV';
    }

    public function export(PayrollRun $run): array
    {
        $rows = [['employee_number', 'employee_name', 'currency', 'code', 'name', 'kind', 'source', 'amount', 'provider_reference']];
        $run->payslips()->with('lines')->orderBy('id')->chunk(200, function ($payslips) use (&$rows, $run) {
            foreach ($payslips as $slip) {
                foreach ($slip->lines as $line) {
                    $rows[] = [$slip->snapshot['employee_number'] ?? '', $slip->snapshot['name'] ?? '', $run->currency, $line->code, $line->name,
                        $line->kind, $line->source, Money::toDecimal($line->amount_minor, $run->currency), $line->provider_reference];
                }
            }
        });

        return ['filename' => "payroll-{$run->public_id}-provider.csv", 'mime' => 'text/csv', 'content' => Csv::build($rows)];
    }

    public function parseResults(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '';
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines) ?? '', escape: ''));
        if (array_diff(['employee_number', 'code', 'name', 'kind', 'amount'], $header) !== []) {
            throw ValidationException::withMessages(['file' => __('The file needs the columns: :columns.', ['columns' => implode(', ', self::COLUMNS)])]);
        }
        if (count($lines) > 20_000) {
            throw ValidationException::withMessages(['file' => __('At most 20,000 rows can be imported at once.')]);
        }
        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_pad(str_getcsv($line, escape: ''), count($header), null);
            $row = array_combine($header, array_slice($cells, 0, count($header)));
            $rows[] = ['employee_number' => trim((string) $row['employee_number']), 'code' => trim((string) $row['code']), 'name' => trim((string) $row['name']),
                'kind' => strtolower(trim((string) $row['kind'])), 'amount' => trim((string) $row['amount']),
                'reference' => isset($row['reference']) && trim((string) $row['reference']) !== '' ? trim((string) $row['reference']) : null, 'row' => $i + 2];
        }

        return $rows;
    }
}
