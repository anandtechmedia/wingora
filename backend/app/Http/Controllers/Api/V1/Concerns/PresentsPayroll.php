<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Payroll\Money;
use App\Payroll\PayrollWorkflow;
use App\Security\Permission as P;

/** Money is always returned as an exact decimal string plus integer minor units and the currency. */
trait PresentsPayroll
{
    protected function money(?int $minor, string $currency): ?array
    {
        return $minor === null ? null : ['amount' => Money::toDecimal($minor, $currency), 'minor' => $minor, 'currency' => $currency];
    }

    protected function presentRun(PayrollRun $run, ?User $user = null): array
    {
        $run->loadMissing(['period.calendar', 'legalEntity:id,public_id,name,country_code,currency_code']);
        $c = $run->currency;
        $data = [
            'id' => $run->public_id, 'type' => $run->type, 'status' => $run->status, 'currency' => $c,
            'legal_entity' => ['id' => $run->legalEntity->public_id, 'name' => $run->legalEntity->name, 'country_code' => $run->legalEntity->country_code],
            'period' => ['id' => $run->period->public_id, 'start_date' => $run->period->start_date?->toDateString(), 'end_date' => $run->period->end_date?->toDateString(), 'pay_date' => $run->period->pay_date?->toDateString(),
                'pay_frequency' => $run->period->calendar->pay_frequency, 'calendar' => $run->period->calendar->name],
            'approval_levels' => $run->approval_levels, 'current_level' => $run->current_level, 'review_cycle' => $run->review_cycle,
            'employee_count' => $run->employee_count, 'gross' => $this->money($run->gross_minor, $c), 'deductions' => $this->money($run->deductions_minor, $c), 'net' => $this->money($run->net_minor, $c),
            'notes' => $run->notes, 'rejection_note' => $run->rejection_note,
            'prepared_at' => $run->prepared_at?->toIso8601String(), 'submitted_at' => $run->submitted_at?->toIso8601String(),
            'approved_at' => $run->approved_at?->toIso8601String(), 'published_at' => $run->published_at?->toIso8601String(),
            'disclaimer' => __('Prepared for review. Not a statutory payroll calculation: tax and statutory amounts are only those entered or supplied by a provider.'),
        ];
        if ($user) {
            $data['can'] = [
                'edit' => $run->isEditable() && $user->hasPermission(P::PayrollManage),
                'submit' => $run->isEditable() && $run->prepared_at !== null && $user->hasPermission(P::PayrollManage),
                'approve' => app(PayrollWorkflow::class)->canApprove($run, $user),
                'publish' => $run->status === 'approved' && $user->hasPermission(P::PayrollManage),
                'export' => $user->hasPermission(P::PayrollExport),
            ];
        }

        return $data;
    }

    protected function presentPayslip(Payslip $slip, bool $withLines = false): array
    {
        $c = $slip->currency;
        $data = ['id' => $slip->public_id, 'employee' => $slip->snapshot, 'currency' => $c, 'days_paid' => $slip->days_paid, 'period_days' => $slip->period_days,
            'gross' => $this->money($slip->gross_minor, $c), 'deductions' => $this->money($slip->deductions_minor, $c), 'net' => $this->money($slip->net_minor, $c),
            'issues' => $slip->issues ?? []];
        if ($withLines) {
            $data['lines'] = $slip->lines->map(fn (PayrollLine $l) => $this->presentLine($l, $c))->values();
        }

        return $data;
    }

    protected function presentLine(PayrollLine $l, string $currency): array
    {
        return ['id' => $l->public_id, 'code' => $l->code, 'name' => $l->name, 'kind' => $l->kind, 'source' => $l->source, 'amount' => $this->money($l->amount_minor, $currency),
            'basis' => $l->basis, 'reason' => $l->reason, 'provider' => $l->provider, 'provider_reference' => $l->provider_reference];
    }
}
