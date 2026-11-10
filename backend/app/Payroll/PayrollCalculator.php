<?php

namespace App\Payroll;

use App\Models\CompensationRecord;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Reimbursement;
use App\Models\SalaryComponent;
use Carbon\CarbonImmutable;

/**
 * Prepares a run's calculated lines from configuration Wingora owns: effective-dated compensation, salary-structure
 * fixed and percentage components, loan instalments and approved reimbursements. It never computes tax or statutory
 * items; those are `manual` or `provider` lines, which recalculation keeps untouched.
 *
 * Arithmetic: integer minor units, each line rounded half away from zero. Base pay for a period is the record's amount
 * converted to the run's frequency, then prorated by calendar days (days covered ÷ days in period).
 */
final class PayrollCalculator
{
    public const PERIODS_PER_YEAR = ['weekly' => 52, 'biweekly' => 26, 'semimonthly' => 24, 'monthly' => 12];

    /** Amount for one full pay period of `$frequency`. */
    public static function periodAmount(int $amountMinor, string $basis, string $recordFrequency, string $frequency): int
    {
        $perYear = self::PERIODS_PER_YEAR[$frequency];

        return match ($basis) {
            'annual' => Money::mulDiv($amountMinor, 1, $perYear),
            'monthly' => Money::mulDiv($amountMinor, 12, $perYear),
            // A per-period amount for a different frequency is first annualised.
            'period' => $recordFrequency === $frequency ? $amountMinor : Money::mulDiv($amountMinor, self::PERIODS_PER_YEAR[$recordFrequency], $perYear),
        };
    }

    public static function days(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
    }

    /** Recalculates every payslip in a draft run. The caller holds the run lock. */
    public function prepare(PayrollRun $run): void
    {
        $period = $run->period;
        $frequency = $period->calendar->pay_frequency;
        $from = $period->start_date?->toDateString();
        $to = $period->end_date?->toDateString();
        $periodDays = self::days($from, $to);

        $employees = Employee::query()
            ->where('legal_entity_id', $run->legal_entity_id)
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $to))
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from))
            ->whereHas('compensationRecords', fn ($q) => $q->overlapping($from, $to))
            ->with(['compensationRecords' => fn ($q) => $q->overlapping($from, $to)->orderBy('effective_from')->with('structure'),
                'department:id,name', 'designation:id,name', 'legalEntity:id,legal_name', 'workLocation:id,name'])
            ->orderBy('id')
            ->get();

        $run->lines()->where('source', 'calculated')->delete();
        $kept = $run->payslips()->whereHas('lines')->pluck('employee_id')->all();
        $run->payslips()->whereNotIn('employee_id', $employees->pluck('id'))->whereNotIn('employee_id', $kept)->delete();

        $offCycle = $run->type === 'off_cycle';
        foreach ($employees as $employee) {
            $slip = Payslip::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->first() ?? new Payslip;
            $slip->forceFill(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'tenant_id' => $run->tenant_id, 'currency' => $run->currency, 'period_days' => $periodDays, 'snapshot' => $this->snapshot($employee, $frequency)]);
            $slip->saveQuietly();
            $issues = [];
            $windowFrom = max($from, $employee->hire_date?->toDateString() ?? $from);
            $windowTo = min($to, $employee->termination_date?->toDateString() ?? $to);
            $base = 0;
            $daysPaid = 0;
            $groups = [];

            // Off-cycle runs carry only entered/provider lines (bonuses, corrections), never a second base salary.
            foreach ($offCycle ? [] : $employee->compensationRecords as $record) {
                /** @var CompensationRecord $record */
                if ($record->currency !== $run->currency) {
                    $issues[] = ['code' => 'currency_mismatch', 'message' => __('Compensation is in :c, not the run currency.', ['c' => $record->currency])];

                    continue;
                }
                $segFrom = max($windowFrom, $record->effective_from?->toDateString());
                $segTo = min($windowTo, $record->effective_to?->toDateString() ?? $windowTo);
                if ($segFrom > $segTo) {
                    continue;
                }
                $segDays = self::days($segFrom, $segTo);
                $full = self::periodAmount($record->amount_minor, $record->basis, $record->pay_frequency, $frequency);
                $amount = Money::mulDiv($full, $segDays, $periodDays);
                $this->line($slip, 'BASE', __('Base salary'), 'earning', $amount, [
                    'method' => 'base_salary', 'from' => $segFrom, 'to' => $segTo, 'days' => $segDays, 'period_days' => $periodDays,
                    'record_amount' => Money::toDecimal($record->amount_minor, $record->currency), 'record_basis' => $record->basis, 'full_period_amount' => Money::toDecimal($full, $run->currency),
                ]);
                $base += $amount;
                $daysPaid += $segDays;
                // Structure components apply only to the days (and base) covered by records carrying that structure.
                if ($record->structure) {
                    $sid = $record->salary_structure_id;
                    $groups[$sid] ??= ['structure' => $record->structure, 'days' => 0, 'base' => 0, 'to' => $segTo];
                    $groups[$sid]['days'] += $segDays;
                    $groups[$sid]['base'] += $amount;
                    $groups[$sid]['to'] = max($groups[$sid]['to'], $segTo);
                }
            }

            $awaiting = [];
            foreach ($groups as $sid => $g) {
                $components = SalaryComponent::query()->where('salary_structure_id', $sid)->effectiveOn($g['to'])->orderBy('sort_order')->get();
                foreach ($components as $c) {
                    $amount = match ($c->method) {
                        'fixed' => $g['days'] < $periodDays ? Money::mulDiv((int) $c->amount_minor, $g['days'], $periodDays) : (int) $c->amount_minor,
                        'percent_of_base' => Money::percent($g['base'], (int) $c->rate_basis_points),
                        default => null,
                    };
                    if ($amount === null) {
                        $awaiting[strtoupper($c->code)] = ['component' => strtoupper($c->code), 'name' => $c->name, 'method' => $c->method];

                        continue;
                    }
                    $this->line($slip, $c->code, $c->name, $c->kind, $amount, array_filter([
                        'method' => $c->method, 'structure' => $g['structure']->code,
                        'fixed_amount' => $c->method === 'fixed' ? Money::toDecimal((int) $c->amount_minor, $run->currency) : null,
                        'rate_percent' => $c->method === 'percent_of_base' ? number_format($c->rate_basis_points / 100, 2, '.', '') : null,
                        'of' => $c->method === 'percent_of_base' ? Money::toDecimal($g['base'], $run->currency) : null,
                        'prorated_days' => $c->method === 'fixed' && $g['days'] < $periodDays ? "{$g['days']}/{$periodDays}" : null,
                    ], fn ($v) => $v !== null));
                }
            }

            if (! $offCycle) {
                $this->loans($run, $slip, $employee, $to, $issues);
            }
            $this->reimbursements($run, $slip, $employee);
            $slip->forceFill(['days_paid' => $daysPaid, 'issues' => $issues ?: null, 'snapshot' => ['awaiting_inputs' => array_values($awaiting)] + $slip->snapshot])->save();
            self::refreshPayslip($slip);
        }

        self::refreshRun($run);
    }

    /** @param list<array<string, mixed>> $issues */
    private function loans(PayrollRun $run, Payslip $slip, Employee $employee, string $to, array &$issues): void
    {
        $loans = Loan::query()->where('employee_id', $employee->id)->where('status', 'active')->where('first_deduction_on', '<=', $to)
            ->withSum('repayments', 'amount_minor')->orderBy('id')->get();
        foreach ($loans as $loan) {
            if ($loan->currency !== $run->currency) {
                $issues[] = ['code' => 'currency_mismatch', 'message' => __('A loan is in :c, not the run currency.', ['c' => $loan->currency])];

                continue;
            }
            $outstanding = $loan->principal_minor - (int) $loan->repayments_sum_amount_minor;
            $amount = min($loan->installment_minor, $outstanding);
            if ($amount <= 0) {
                continue;
            }
            $this->line($slip, 'LOAN', __('Loan repayment'), 'deduction', $amount, [
                'method' => 'loan_installment', 'installment' => Money::toDecimal($loan->installment_minor, $run->currency),
                'outstanding_before' => Money::toDecimal($outstanding, $run->currency), 'outstanding_after' => Money::toDecimal($outstanding - $amount, $run->currency),
            ], loanId: $loan->id);
        }
    }

    private function reimbursements(PayrollRun $run, Payslip $slip, Employee $employee): void
    {
        $items = Reimbursement::query()->where('employee_id', $employee->id)->where('status', 'approved')->whereNull('paid_run_id')
            ->where('currency', $run->currency)->orderBy('id')->get();
        foreach ($items as $item) {
            $this->line($slip, 'REIMB', __('Reimbursement: :category', ['category' => $item->category]), 'earning', $item->amount_minor, [
                'method' => 'approved_reimbursement', 'expense_date' => $item->expense_date?->toDateString(), 'reimbursement' => $item->public_id,
            ], reimbursementId: $item->id);
        }
    }

    private function line(Payslip $slip, string $code, string $name, string $kind, int $amount, array $basis, ?int $loanId = null, ?int $reimbursementId = null): void
    {
        $line = new PayrollLine(['code' => $code, 'name' => $name, 'kind' => $kind, 'amount_minor' => $amount]);
        $line->forceFill(['tenant_id' => $slip->tenant_id, 'payslip_id' => $slip->id, 'payroll_run_id' => $slip->payroll_run_id, 'source' => 'calculated', 'basis' => $basis,
            'loan_id' => $loanId, 'reimbursement_id' => $reimbursementId]);
        $line->saveQuietly();
    }

    private function snapshot(Employee $e, string $frequency): array
    {
        return ['name' => $e->fullName(), 'employee_number' => $e->employee_number, 'job_title' => $e->designation?->name ?? $e->job_title,
            'department' => $e->department?->name, 'legal_entity' => $e->legalEntity?->legal_name, 'work_location' => $e->workLocation?->name,
            'pay_frequency' => $frequency, 'employee' => $e->public_id];
    }

    public static function refreshPayslip(Payslip $slip): void
    {
        $sums = $slip->lines()->toBase()->selectRaw("sum(case when kind = 'earning' then amount_minor else 0 end) as gross, sum(case when kind = 'deduction' then amount_minor else 0 end) as ded")->first();
        $gross = (int) ($sums->gross ?? 0);
        $ded = (int) ($sums->ded ?? 0);
        $issues = array_values(array_filter($slip->issues ?? [], fn ($i) => ! in_array($i['code'], ['negative_net', 'awaiting_input'], true)));
        $filled = array_map('strtoupper', $slip->lines()->whereIn('source', ['manual', 'provider'])->pluck('code')->all());
        foreach ($slip->snapshot['awaiting_inputs'] ?? [] as $a) {
            if (! in_array($a['component'], $filled, true)) {
                $issues[] = ['code' => 'awaiting_input', 'message' => __(':name needs an entered or provider value.', ['name' => $a['name']]), 'component' => $a['component'], 'method' => $a['method']];
            }
        }
        if ($gross - $ded < 0) {
            $issues[] = ['code' => 'negative_net', 'message' => __('Deductions exceed earnings.')];
        }
        $slip->forceFill(['gross_minor' => $gross, 'deductions_minor' => $ded, 'net_minor' => $gross - $ded, 'issues' => $issues ?: null])->saveQuietly();
    }

    public static function refreshRun(PayrollRun $run): void
    {
        $t = $run->payslips()->toBase()->selectRaw('count(*) as n, sum(gross_minor) as g, sum(deductions_minor) as d, sum(net_minor) as net')->first();
        $run->forceFill(['employee_count' => (int) $t->n, 'gross_minor' => (int) $t->g, 'deductions_minor' => (int) $t->d, 'net_minor' => (int) $t->net])->saveQuietly();
    }
}
