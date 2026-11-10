<?php

namespace App\Payroll;

use App\Audit\AuditEvents;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PayrollRunApproval;
use App\Models\Payslip;
use App\Models\Reimbursement;
use App\Models\User;
use App\Payroll\Providers\PayrollProvider;
use App\Security\Permission as P;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Run lifecycle: draft → in_review → approved → published. Every transition locks the run row, so concurrent
 * actions resolve to one outcome. Approvers must hold payroll.approve, cannot be the preparer or submitter, and each
 * level in a review cycle needs a different person. Approved runs are immutable; corrections use an off-cycle run.
 */
final class PayrollWorkflow
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    public function create(PayrollPeriod $period, string $type, ?string $notes, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($period, $type, $notes, $actor) {
            $period = PayrollPeriod::query()->whereKey($period->id)->lockForUpdate()->with('calendar.legalEntity')->firstOrFail();
            $entity = $period->calendar->legalEntity;
            if ($type === 'regular' && PayrollRun::query()->where('regular_period_id', $period->id)->exists()) {
                abort(409, __('This period already has a regular run. Use an off-cycle run for corrections.'));
            }
            $run = new PayrollRun(['notes' => $notes]);
            $run->forceFill(['tenant_id' => $period->tenant_id, 'payroll_period_id' => $period->id, 'legal_entity_id' => $entity->id, 'currency' => $entity->currency_code, 'type' => $type,
                'regular_period_id' => $type === 'regular' ? $period->id : null, 'status' => 'draft',
                'approval_levels' => $period->calendar->approval_levels, 'created_by' => $actor->id]);
            $run->saveQuietly();
            AuditLog::record(AuditEvents::PAYROLL_RUN_CREATED, $run, metadata: ['type' => $type, 'period' => $period->public_id, 'currency' => $run->currency]);

            return $run;
        });
    }

    public function prepare(PayrollRun $run, User $actor): PayrollRun
    {
        return $this->editing($run, function (PayrollRun $locked) use ($actor) {
            $this->calculator->prepare($locked);
            $locked->forceFill(['prepared_by' => $actor->id, 'prepared_at' => now()])->saveQuietly();
            AuditLog::record(AuditEvents::PAYROLL_RUN_PREPARED, $locked, metadata: ['employees' => $locked->employee_count, 'currency' => $locked->currency]);
        });
    }

    /** @param array{code: string, name: string, kind: string, amount: string, reason: string} $data */
    public function addManualLine(Payslip $slip, array $data, User $actor): PayrollLine
    {
        $line = null;
        $this->editing($slip->run, function (PayrollRun $run) use ($slip, $data, $actor, &$line) {
            $line = $this->newLine($slip, $data, 'manual', $actor);
            $line->forceFill(['reason' => $data['reason']])->saveQuietly();
            PayrollCalculator::refreshPayslip($slip->refresh());
            PayrollCalculator::refreshRun($run);
            AuditLog::record(AuditEvents::PAYROLL_LINE_ENTERED, $run, new: ['code' => $line->code, 'kind' => $line->kind, 'source' => 'manual', 'amount' => '[REDACTED]'],
                metadata: ['payslip' => $slip->public_id, 'reason' => $data['reason']]);
        });

        return $line;
    }

    public function removeLine(PayrollLine $line): void
    {
        abort_if($line->source === 'calculated', 422, __('Calculated lines change only by recalculating the run.'));
        $slip = $line->payslip;
        $this->editing($slip->run, function (PayrollRun $run) use ($line, $slip) {
            $line->deleteQuietly();
            PayrollCalculator::refreshPayslip($slip->refresh());
            PayrollCalculator::refreshRun($run);
            AuditLog::record(AuditEvents::PAYROLL_LINE_REMOVED, $run, old: ['code' => $line->code, 'kind' => $line->kind, 'source' => $line->source], metadata: ['payslip' => $slip->public_id]);
        });
    }

    /** Replaces all provider lines in the run with the parsed file. All rows must be valid or nothing is imported. */
    public function importProviderResults(PayrollRun $run, PayrollProvider $provider, string $content, User $actor): int
    {
        $rows = $provider->parseResults($content);
        $count = 0;
        $this->editing($run, function (PayrollRun $locked) use ($rows, $provider, $actor, &$count) {
            $slips = Payslip::query()->where('payroll_run_id', $locked->id)->with('employee:id,employee_number')->get()
                ->keyBy(fn (Payslip $s) => (string) $s->employee->employee_number);
            $errors = [];
            foreach ($rows as $row) {
                if (! $slips->has($row['employee_number'])) {
                    $errors["row.{$row['row']}"] = __('Employee :n is not in this run.', ['n' => $row['employee_number']]);
                } elseif (! in_array($row['kind'], ['earning', 'deduction'], true) || $row['code'] === '' || $row['name'] === '') {
                    $errors["row.{$row['row']}"] = __('Each row needs a code, a name and a kind of earning or deduction.');
                } else {
                    try {
                        $amount = Money::parse($row['amount'], $locked->currency);
                        abort_if($amount < 0, 422);
                    } catch (\Throwable) {
                        $errors["row.{$row['row']}"] = __('The amount must be a positive number in :c.', ['c' => $locked->currency]);
                    }
                }
            }
            if ($errors) {
                throw ValidationException::withMessages(array_slice($errors, 0, 100, true));
            }
            $locked->lines()->where('source', 'provider')->delete();
            foreach ($rows as $row) {
                $slip = $slips[$row['employee_number']];
                $line = $this->newLine($slip, $row, 'provider', $actor);
                $line->forceFill(['provider' => $provider->key(), 'provider_reference' => $row['reference'] ? mb_substr($row['reference'], 0, 120) : null])->saveQuietly();
                $count++;
            }
            foreach ($slips as $slip) {
                PayrollCalculator::refreshPayslip($slip->refresh());
            }
            PayrollCalculator::refreshRun($locked);
            AuditLog::record(AuditEvents::PAYROLL_PROVIDER_IMPORTED, $locked, metadata: ['provider' => $provider->key(), 'lines' => $count]);
        });

        return $count;
    }

    public function submit(PayrollRun $run, User $actor): PayrollRun
    {
        return $this->editing($run, function (PayrollRun $locked) use ($actor) {
            abort_if($locked->prepared_at === null || $locked->employee_count === 0, 422, __('Prepare the run before submitting it.'));
            abort_unless($locked->lines()->exists(), 422, __('The run has no payroll lines to approve.'));
            $blocking = $locked->payslips()->get()->filter(fn (Payslip $s) => collect($s->issues ?? [])->contains(fn ($i) => in_array($i['code'], ['negative_net', 'currency_mismatch'], true)));
            if ($blocking->isNotEmpty()) {
                throw ValidationException::withMessages(['run' => __(':n payslips have blocking issues (negative net pay or currency mismatch).', ['n' => $blocking->count()])]);
            }
            $locked->forceFill(['status' => 'in_review', 'review_cycle' => $locked->review_cycle + 1, 'current_level' => 1,
                'submitted_by' => $actor->id, 'submitted_at' => now(), 'rejection_note' => null])->saveQuietly();
            AuditLog::record(AuditEvents::PAYROLL_RUN_SUBMITTED, $locked, metadata: ['review_cycle' => $locked->review_cycle, 'levels' => $locked->approval_levels]);
        }, allowFrom: 'draft');
    }

    public function canApprove(PayrollRun $run, User $user): bool
    {
        return $run->status === 'in_review'
            && $user->hasPermission(P::PayrollApprove)
            && (int) $run->tenant_id === (int) $user->tenant_id
            && $user->id !== $run->prepared_by
            && $user->id !== $run->submitted_by
            && ! PayrollRunApproval::query()->where('payroll_run_id', $run->id)->where('review_cycle', $run->review_cycle)->where('decided_by', $user->id)->exists();
    }

    public function decide(PayrollRun $run, User $actor, bool $approve, ?string $note): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor, $approve, $note) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'in_review', 409, __('This run is not awaiting approval.'));
            abort_unless($this->canApprove($locked, $actor), 403, __('You cannot approve this run. Preparers and earlier approvers are excluded.'));
            $approval = new PayrollRunApproval(['note' => $note]);
            $approval->forceFill(['payroll_run_id' => $locked->id, 'review_cycle' => $locked->review_cycle, 'level' => $locked->current_level,
                'decision' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            $level = $locked->current_level;

            if (! $approve) {
                $locked->forceFill(['status' => 'draft', 'current_level' => 0, 'rejection_note' => $note])->saveQuietly();
                AuditLog::record(AuditEvents::PAYROLL_RUN_REJECTED, $locked, metadata: ['level' => $level, 'review_cycle' => $locked->review_cycle, 'note' => $note]);

                return $locked;
            }

            $final = $level >= $locked->approval_levels;
            if (! $final) {
                $locked->forceFill(['current_level' => $level + 1])->saveQuietly();
            } else {
                $locked->forceFill(['status' => 'approved', 'approved_at' => now()])->saveQuietly();
                $this->settle($locked);
            }
            AuditLog::record(AuditEvents::PAYROLL_RUN_APPROVED, $locked, metadata: ['level' => $level, 'final' => $final, 'review_cycle' => $locked->review_cycle]);

            return $locked;
        });
    }

    public function publish(PayrollRun $run, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'approved', 409, __('Only approved runs can be published.'));
            $locked->forceFill(['status' => 'published', 'published_by' => $actor->id, 'published_at' => now()])->saveQuietly();
            AuditLog::record(AuditEvents::PAYROLL_RUN_PUBLISHED, $locked, metadata: ['employees' => $locked->employee_count]);

            return $locked;
        });
    }

    /** Records loan repayments and marks reimbursements paid, exactly once (the run is locked and unique keys guard repayments). */
    private function settle(PayrollRun $run): void
    {
        $lines = PayrollLine::query()->where('payroll_run_id', $run->id)->where(fn ($q) => $q->whereNotNull('loan_id')->orWhereNotNull('reimbursement_id'))->get();
        foreach ($lines->whereNotNull('loan_id') as $line) {
            $repayment = new LoanRepayment;
            $repayment->forceFill(['loan_id' => $line->loan_id, 'payroll_run_id' => $run->id, 'amount_minor' => $line->amount_minor])->save();
            $loan = $line->loan_id ? Loan::query()->lockForUpdate()->find($line->loan_id) : null;
            if ($loan && $loan->outstanding() <= 0) {
                $loan->forceFill(['status' => 'closed'])->save();
            }
        }
        $ids = $lines->whereNotNull('reimbursement_id')->pluck('reimbursement_id')->all();
        if ($ids) {
            $paid = Reimbursement::query()->whereIn('id', $ids)->where('status', 'approved')->whereNull('paid_run_id')->update(['status' => 'paid', 'paid_run_id' => $run->id]);
            if ($paid !== count($ids)) {
                throw new InvalidArgumentException('A reimbursement in this run was changed after preparation. Recalculate the run.');
            }
        }
    }

    private function newLine(Payslip $slip, array $data, string $source, User $actor): PayrollLine
    {
        $currency = $slip->currency;
        $amount = is_int($data['amount'] ?? null) ? $data['amount'] : Money::parse((string) $data['amount'], $currency);
        $line = new PayrollLine(['code' => strtoupper(mb_substr($data['code'], 0, 40)), 'name' => mb_substr($data['name'], 0, 160), 'kind' => $data['kind'], 'amount_minor' => $amount]);
        $line->forceFill(['tenant_id' => $slip->tenant_id, 'payslip_id' => $slip->id, 'payroll_run_id' => $slip->payroll_run_id, 'source' => $source, 'created_by' => $actor->id]);

        return $line;
    }

    /** Runs `$fn` with the run locked and in an editable state. */
    private function editing(PayrollRun $run, callable $fn, string $allowFrom = 'draft'): PayrollRun
    {
        return DB::transaction(function () use ($run, $fn, $allowFrom) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->with('period.calendar')->firstOrFail();
            abort_unless($locked->status === $allowFrom, 409, __('Approved or submitted runs cannot be changed. Reject it back to draft, or use an off-cycle run.'));
            $fn($locked);

            return $locked->refresh();
        });
    }

    public static function employeeInRun(PayrollRun $run, Employee $employee): bool
    {
        return $run->payslips()->where('employee_id', $employee->id)->exists();
    }
}
