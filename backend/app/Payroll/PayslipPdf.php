<?php

namespace App\Payroll;

use App\Models\PayrollLine;
use App\Models\Payslip;

/** Renders a payslip. Each line shows its source so calculated, entered and provider values are never confused. */
final class PayslipPdf
{
    public function render(Payslip $slip): string
    {
        $slip->loadMissing(['run.period.calendar', 'run.legalEntity', 'lines']);
        $run = $slip->run;
        $c = $slip->currency;
        $s = $slip->snapshot;
        $pdf = new Pdf("Payslip {$s['employee_number']} {$run->period->start_date?->toDateString()}");
        $pdf->rect(0, 0, 612, 70, 0.94);
        $pdf->text(40, 30, 'Wingora', 18, true);
        $pdf->text(40, 50, 'Payslip', 10);
        $pdf->text(372, 30, (string) $run->legalEntity->legal_name, 10, true, 'right', 200);
        $pdf->text(372, 50, $run->status === 'published' ? 'Published' : 'Preview - not published', 9, false, 'right', 200);
        $y = 100;
        $pairs = [['Employee', $s['name'] ?? ''], ['Employee number', $s['employee_number'] ?? ''], ['Job title', $s['job_title'] ?? ''], ['Department', $s['department'] ?? ''],
            ['Pay period', "{$run->period->start_date?->toDateString()} to {$run->period->end_date?->toDateString()}"], ['Pay date', $run->period->pay_date?->toDateString()],
            ['Days paid', "{$slip->days_paid} of {$slip->period_days}"], ['Run', ($run->type === 'off_cycle' ? 'Off-cycle' : 'Regular')." ({$c})"]];
        foreach ($pairs as $i => [$label, $value]) {
            $x = $i % 2 === 0 ? 40 : 320;
            $pdf->text($x, $y, $label, 8);
            $pdf->text($x, $y + 12, (string) $value, 10, true);
            if ($i % 2 === 1) {
                $y += 32;
            }
        }
        $y += 10;
        foreach (['earning' => 'Earnings', 'deduction' => 'Deductions'] as $kind => $title) {
            $pdf->rect(40, $y, 532, 18, 0.93);
            $pdf->text(46, $y + 12, $title, 9, true);
            $pdf->text(360, $y + 12, 'Source', 9, true);
            $pdf->text(452, $y + 12, "Amount ({$c})", 9, true, 'right', 114);
            $y += 30;
            foreach ($slip->lines->where('kind', $kind) as $line) {
                /** @var PayrollLine $line */
                if ($y > 700) {
                    $pdf->addPage();
                    $y = 50;
                }
                $pdf->text(46, $y, $line->name, 9);
                $pdf->text(360, $y, self::sourceLabel($line), 8);
                $pdf->text(452, $y, Money::format($line->amount_minor, $c), 9, false, 'right', 114);
                $pdf->line(40, $y + 5, 572, $y + 5, 0.3, 0.88);
                $y += 16;
            }
            $total = $kind === 'earning' ? $slip->gross_minor : $slip->deductions_minor;
            $pdf->text(46, $y + 2, "Total {$title}", 9, true);
            $pdf->text(452, $y + 2, Money::format($total, $c), 9, true, 'right', 114);
            $y += 26;
        }
        $pdf->line(40, $y, 572, $y, 1, 0.2);
        $pdf->text(46, $y + 20, 'Net pay', 12, true);
        $pdf->text(412, $y + 20, Money::format($slip->net_minor, $c).' '.$c, 12, true, 'right', 154);
        $pdf->text(40, 750, 'Calculated = by Wingora from configured pay data. Entered = by payroll staff. Provider = from the payroll provider.', 7);
        $pdf->text(40, 762, 'Prepared for review and record-keeping. Not a statutory payroll calculation; tax and statutory items appear only as entered or supplied.', 7);

        return $pdf->output();
    }

    public static function sourceLabel(PayrollLine $line): string
    {
        return match ($line->source) {
            'calculated' => 'Calculated',
            'manual' => 'Entered',
            'provider' => 'Provider'.($line->provider_reference ? " ({$line->provider_reference})" : ''),
        };
    }
}
