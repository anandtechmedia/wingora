<?php

namespace App\Documents;

use App\Models\DocumentRequest;
use App\Payroll\Pdf;

/** Renders an approved request as a plain letter: placeholders are filled from employment data, answers and HR-typed values only. */
final class LetterPdf
{
    /** Placeholders a template body may use, besides answers.<key> and (salary templates) salary. */
    public const PLACEHOLDERS = ['employee.name', 'employee.first_name', 'employee.last_name', 'employee.number', 'employee.job_title', 'employee.department',
        'employee.designation', 'employee.hire_date', 'employee.employment_type', 'entity.legal_name', 'entity.country', 'today', 'request.number'];

    /** @return list<string> tokens found in the body */
    public static function tokens(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> placeholders in the body that the template cannot fill */
    public static function unknownTokens(string $body, array $fieldKeys, bool $salary): array
    {
        $allowed = [...self::PLACEHOLDERS, ...array_map(fn ($k) => "answers.$k", $fieldKeys), ...($salary ? ['salary'] : [])];

        return array_values(array_diff(self::tokens($body), $allowed));
    }

    public function render(DocumentRequest $r, ?string $salary): string
    {
        $r->loadMissing(['template', 'employee.department', 'employee.designation', 'employee.legalEntity']);
        $e = $r->employee;
        $entity = $e->legalEntity;
        $values = [
            'employee.name' => trim(($e->preferred_name ?: $e->first_name).' '.$e->last_name),
            'employee.first_name' => $e->first_name,
            'employee.last_name' => $e->last_name,
            'employee.number' => $e->employee_number,
            'employee.job_title' => (string) $e->job_title,
            'employee.department' => (string) $e->department?->name,
            'employee.designation' => (string) $e->designation?->name,
            'employee.hire_date' => (string) $e->hire_date?->toDateString(),
            'employee.employment_type' => str_replace('_', ' ', (string) $e->employment_type),
            'entity.legal_name' => (string) ($entity?->legal_name ?: $entity?->name),
            'entity.country' => (string) $entity?->country_code,
            'today' => now()->toDateString(),
            'request.number' => (string) $r->number,
            'salary' => (string) $salary,
        ];
        foreach ($r->answers ?? [] as $k => $v) {
            $values["answers.$k"] = (string) $v;
        }
        $body = preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', fn ($m) => $values[$m[1]] ?? '', (string) $r->template->body);

        $pdf = new Pdf($r->template->name.' #'.$r->number);
        $pdf->rect(0, 0, 612, 70, 0.94);
        $pdf->text(40, 32, $values['entity.legal_name'] ?: 'Wingora', 14, true);
        $pdf->text(40, 52, $r->template->name, 10);
        $pdf->text(372, 32, 'Date: '.$values['today'], 9, false, 'right', 200);
        $pdf->text(372, 52, 'Reference: DR-'.$r->number, 9, false, 'right', 200);
        $y = 110;
        foreach (preg_split('/\R/', $body) as $paragraph) {
            foreach (self::wrap($paragraph, 92) as $line) {
                if ($y > 740) {
                    $pdf->addPage();
                    $y = 60;
                }
                $pdf->text(40, $y, $line, 11);
                $y += 16;
            }
        }
        $pdf->line(40, 760, 572, 760, 0.3, 0.8);
        $pdf->text(40, 775, 'Issued through Wingora on approval of request DR-'.$r->number.'.', 8);

        return $pdf->output();
    }

    /** @return list<string> */
    private static function wrap(string $text, int $width): array
    {
        $text = rtrim($text);
        if ($text === '') {
            return [''];
        }
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text) as $word) {
            while (mb_strlen($word) > $width) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }
            $candidate = $line === '' ? $word : "$line $word";
            if (mb_strlen($candidate) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        $lines[] = $line;

        return $lines;
    }
}
