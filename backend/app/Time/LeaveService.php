<?php

namespace App\Time;

use App\Audit\AuditEvents;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Security\Permission as P;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave balances are sums of append-only ledger entries. Entitlements come only from tenant-configured,
 * effective-dated LeavePolicy rows. The leave year is the calendar year.
 */
class LeaveService
{
    public function __construct(private readonly ScheduleResolver $schedules, private readonly HolidayService $holidays) {}

    public static function countryOf(Employee $employee): ?string
    {
        return $employee->workLocation?->country_code ?? $employee->legalEntity?->country_code;
    }

    public function appliesTo(LeaveType $type, Employee $employee): bool
    {
        return $type->country_code === null || $type->country_code === self::countryOf($employee);
    }

    /** Entity-specific policies win over organization-wide ones. */
    public function policyFor(LeaveType $type, Employee $employee, string $date): ?LeavePolicy
    {
        return LeavePolicy::query()->where('leave_type_id', $type->id)->effectiveOn($date)
            ->where(fn ($q) => $q->whereNull('legal_entity_id')->when($employee->legal_entity_id, fn ($q, $id) => $q->orWhere('legal_entity_id', $id)))
            ->orderByRaw('legal_entity_id is null')->orderByDesc('effective_from')->first();
    }

    /** Working days in the employee's schedule, excluding their location's days off. */
    public function countDays(Employee $employee, string $start, string $end, ?string $halfDay = null): float
    {
        $off = $this->holidays->daysOff($employee->workLocation, $start, $end);
        $days = 0;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $date = $day->toDateString();
            if (! isset($off[$date]) && $this->schedules->isWorkingDay($employee, $date)) {
                $days++;
            }
        }

        return $halfDay && $days === 1 ? 0.5 : (float) $days;
    }

    /** @return array{entitled: float, carried_forward: float, adjustments: float, used: float, lapsed: float, carried_out: float, expired: float, pending: float, available: float} */
    public function balance(Employee $employee, LeaveType $type, int $year, ?string $asOf = null, ?int $excludeRequest = null): array
    {
        $asOf ??= TimeZones::today($employee);
        $entries = LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('period_year', $year)
            ->with('request:id,start_date')->get();
        $sum = fn (array $types) => round($entries->whereIn('entry_type', $types)->sum('amount'), 2);

        $expired = 0.0;
        foreach ($entries->where('entry_type', 'carry_forward') as $cf) {
            if ($cf->expires_on && $cf->expires_on->toDateString() < $asOf) {
                $usedBefore = -$entries->whereIn('entry_type', ['deduction', 'reversal'])
                    ->filter(fn ($e) => $e->request && $e->request->start_date->toDateString() <= $cf->expires_on->toDateString())->sum('amount');
                $expired += max(0, $cf->amount - max(0, $usedBefore));
            }
        }

        $pending = (float) LeaveRequest::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->where('status', 'pending')->whereYear('start_date', $year)->when($excludeRequest, fn ($q, $id) => $q->whereKeyNot($id))->sum('days');

        $total = round($entries->sum('amount'), 2);

        return [
            'entitled' => $sum(['allocation', 'accrual']), 'carried_forward' => $sum(['carry_forward']), 'adjustments' => $sum(['adjustment']),
            'used' => -$sum(['deduction', 'reversal']), 'lapsed' => -$sum(['lapse']), 'carried_out' => -$sum(['carry_out']), 'expired' => round($expired, 2), 'pending' => round($pending, 2),
            'available' => round($total - $expired - $pending, 2),
        ];
    }

    public function apply(Employee $employee, LeaveType $type, array $data, User $actor): LeaveRequest
    {
        $start = $data['start_date'];
        $end = $data['end_date'];
        $half = $data['half_day'] ?? null;
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($type->status !== 'active' || ! $this->appliesTo($type, $employee)) {
            $fail('leave_type_id', __('This leave type is not available to the employee.'));
        }
        if (substr($start, 0, 4) !== substr($end, 0, 4)) {
            $fail('end_date', __('A request cannot span two leave years; submit one per year.'));
        }
        if ($half && ($start !== $end || ! $type->allow_half_day)) {
            $fail('half_day', __('Half days are only allowed for a single day on leave types that permit them.'));
        }
        if ($type->requires_document && empty($data['document_id'])) {
            $fail('document_id', __('This leave type requires a supporting document.'));
        }
        $policy = $this->policyFor($type, $employee, $start) ?? $fail('start_date', __('No leave policy is in effect for this date.'));
        $today = TimeZones::today($employee);
        if (! $actor->hasPermission(P::LeaveManage) && $policy->min_notice_days > 0
            && $start < CarbonImmutable::parse($today)->addDays($policy->min_notice_days)->toDateString()) {
            $fail('start_date', __('This leave type needs :days days\' notice.', ['days' => $policy->min_notice_days]));
        }

        $days = $this->countDays($employee, $start, $end, $half);
        if ($days <= 0) {
            $fail('start_date', __('The selected dates contain no working days.'));
        }
        if ($policy->max_consecutive_days && $days > $policy->max_consecutive_days) {
            $fail('end_date', __('At most :days consecutive days may be requested.', ['days' => $policy->max_consecutive_days]));
        }

        return DB::transaction(function () use ($employee, $type, $data, $actor, $start, $end, $half, $days, $policy, $fail) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $overlap = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
                ->where('start_date', '<=', $end)->where('end_date', '>=', $start)->exists();
            if ($overlap) {
                $fail('start_date', __('These dates overlap another leave request.'));
            }
            if (! $policy->allow_negative && $this->balance($employee, $type, (int) substr($start, 0, 4))['available'] < $days) {
                $fail('end_date', __('Insufficient leave balance.'));
            }

            $request = new LeaveRequest(['reason' => $data['reason'] ?? null]);
            $request->forceFill(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $end, 'half_day' => $half,
                'days' => $days, 'status' => 'pending', 'current_step' => 1, 'document_id' => $data['document_id'] ?? null, 'requested_by' => $actor->id])->save();
            AuditLog::record('leave.request_submitted', $request, [], ['days' => $days, 'start_date' => $start, 'end_date' => $end], ['employee' => $employee->public_id, 'leave_type' => $type->code]);

            return $request;
        });
    }

    /**
     * Locks the request row, then checks it is still pending at this step. A concurrent second decision either
     * waits and sees the new status (409) or hits the unique (request, step) index.
     */
    public function decide(LeaveRequest $request, User $actor, bool $approve, ?string $note): LeaveRequest
    {
        try {
            return DB::transaction(function () use ($request, $actor, $approve, $note) {
                $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                $employee = $locked->employee;
                $type = $locked->leaveType;
                $step = $locked->current_step;

                $allowed = $step === 1
                    ? Approvers::canDecide($actor, $employee, P::LeaveApprove, P::LeaveManage)
                    : $actor->hasPermission(P::LeaveManage) && $actor->employee?->id !== $employee->id
                        && ! $locked->approvals()->where('decided_by', $actor->id)->exists();
                if (! $allowed) {
                    throw new AuthorizationException;
                }
                abort_unless($locked->status === 'pending', 409, __('This request has already been decided.'));

                $approval = new LeaveRequestApproval(['note' => $note]);
                $approval->forceFill(['leave_request_id' => $locked->id, 'step' => $step, 'decision' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now()])->save();

                if (! $approve) {
                    $locked->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();
                    AuditLog::record(AuditEvents::LEAVE_REQUEST_REJECTED, $locked, metadata: ['step' => $step, 'note' => $note, 'employee' => $employee->public_id]);

                    return $locked;
                }

                if ($step < $type->approval_steps) {
                    $locked->forceFill(['current_step' => $step + 1])->save();
                    AuditLog::record(AuditEvents::LEAVE_REQUEST_APPROVED, $locked, metadata: ['step' => $step, 'final' => false, 'employee' => $employee->public_id]);

                    return $locked;
                }

                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
                $year = (int) $locked->start_date->format('Y');
                $policy = $this->policyFor($type, $employee, $locked->start_date->toDateString());
                if (! ($policy?->allow_negative) && $this->balance($employee, $type, $year, excludeRequest: $locked->id)['available'] < $locked->days) {
                    throw ValidationException::withMessages(['request' => __('Insufficient leave balance to approve this request.')]);
                }
                $this->entry($employee, $type, $year, 'deduction', -$locked->days, $locked->start_date->toDateString(), $actor, "deduct:{$locked->id}", request: $locked);
                $locked->forceFill(['status' => 'approved', 'decided_at' => now()])->save();
                AuditLog::record(AuditEvents::LEAVE_REQUEST_APPROVED, $locked, metadata: ['step' => $step, 'final' => true, 'days' => $locked->days, 'employee' => $employee->public_id]);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, __('This request has already been decided.'));
        }
    }

    public function cancel(LeaveRequest $request, User $actor, ?string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $employee = $locked->employee;
            $own = $actor->employee?->id === $employee->id;
            $hr = $actor->hasPermission(P::LeaveManage) && (int) $actor->tenant_id === (int) $employee->tenant_id;
            if (! $own && ! $hr) {
                throw new AuthorizationException;
            }
            abort_unless(in_array($locked->status, ['pending', 'approved'], true), 409, __('Only pending or approved requests can be cancelled.'));
            if ($locked->status === 'approved' && ! $hr && $locked->start_date->toDateString() <= TimeZones::today($employee)) {
                abort(409, __('Leave that has started can only be cancelled by HR.'));
            }

            if ($locked->status === 'approved') {
                $this->entry($employee, $locked->leaveType, (int) $locked->start_date->format('Y'), 'reversal', $locked->days, $locked->start_date->toDateString(), $actor, "reverse:{$locked->id}", request: $locked);
            }
            $previous = $locked->status;
            $locked->forceFill(['status' => 'cancelled', 'cancellation_reason' => $reason])->save();
            AuditLog::record('leave.request_cancelled', $locked, ['status' => $previous], ['status' => 'cancelled'], ['reason' => $reason, 'employee' => $employee->public_id]);

            return $locked;
        });
    }

    /** Idempotent: re-running for the same year (or month) creates no duplicate entries. */
    public function allocate(LeaveType $type, int $year, User $actor, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $created = 0;
        $employees = Employee::query()->with(['workLocation', 'legalEntity'])->whereIn('status', ['active', 'on_leave'])
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', "{$year}-12-31"))
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', "{$year}-01-01"));

        $employees->chunkById(200, function ($chunk) use ($type, $year, $actor, $asOf, &$created) {
            foreach ($chunk as $employee) {
                if (! $this->appliesTo($type, $employee)) {
                    continue;
                }
                $hire = $employee->hire_date?->format('Y') == $year ? $employee->hire_date : null;
                $from = $hire ? $hire->toDateString() : "{$year}-01-01";
                $policy = $this->policyFor($type, $employee, $from);
                if (! $policy || $policy->accrual === 'none') {
                    continue;
                }
                if ($policy->accrual === 'annual') {
                    $months = $hire && $policy->prorate_new_joiners ? 13 - (int) $hire->format('n') : 12;
                    $created += $this->entry($employee, $type, $year, 'allocation', round($policy->annual_entitlement * $months / 12, 2), $from, $actor, "alloc:{$type->id}:{$employee->id}:{$year}") ? 1 : 0;

                    continue;
                }
                $lastMonth = (int) substr($asOf, 0, 4) > $year ? 12 : ((int) substr($asOf, 0, 4) < $year ? 0 : (int) substr($asOf, 5, 2));
                for ($m = $hire ? (int) $hire->format('n') : 1; $m <= $lastMonth; $m++) {
                    $monthStart = sprintf('%d-%02d-01', $year, $m);
                    $monthPolicy = $this->policyFor($type, $employee, $monthStart) ?? $policy;
                    $created += $this->entry($employee, $type, $year, 'accrual', round($monthPolicy->annual_entitlement / 12, 2), $monthStart, $actor, "accrue:{$type->id}:{$employee->id}:{$year}-{$m}") ? 1 : 0;
                }
            }
        });

        AuditLog::record('leave.allocated', $type, [], [], ['year' => $year, 'entries' => $created]);

        return ['entries_created' => $created];
    }

    /** Moves up to the next year's policy cap into the next year; the rest lapses. Idempotent per employee and year. */
    public function carryForward(LeaveType $type, int $fromYear, User $actor): array
    {
        $carried = 0;
        $employeeIds = LeaveLedgerEntry::query()->where('leave_type_id', $type->id)->where('period_year', $fromYear)->distinct()->pluck('employee_id');
        Employee::query()->with(['workLocation', 'legalEntity'])->whereIn('id', $employeeIds)->chunkById(200, function ($chunk) use ($type, $fromYear, $actor, &$carried) {
            foreach ($chunk as $employee) {
                if (LeaveLedgerEntry::query()->where('idempotency_key', "cf:{$type->id}:{$employee->id}:{$fromYear}")->exists()) {
                    continue;
                }
                $next = ($fromYear + 1).'-01-01';
                $policy = $this->policyFor($type, $employee, $next);
                $available = max(0, $this->balance($employee, $type, $fromYear, "{$fromYear}-12-31")['available']);
                $cf = $policy ? min($available, $policy->carry_forward_max) : 0;
                $expires = $policy?->carry_forward_expiry_months ? CarbonImmutable::parse($next)->addMonths($policy->carry_forward_expiry_months)->subDay()->toDateString() : null;
                DB::transaction(function () use ($employee, $type, $fromYear, $actor, $cf, $available, $next, $expires) {
                    $this->entry($employee, $type, $fromYear + 1, 'carry_forward', $cf, $next, $actor, "cf:{$type->id}:{$employee->id}:{$fromYear}", expiresOn: $expires);
                    if ($cf > 0) {
                        $this->entry($employee, $type, $fromYear, 'carry_out', -$cf, "{$fromYear}-12-31", $actor, "cfout:{$type->id}:{$employee->id}:{$fromYear}");
                    }
                    if ($available - $cf > 0) {
                        $this->entry($employee, $type, $fromYear, 'lapse', -round($available - $cf, 2), "{$fromYear}-12-31", $actor, "lapse:{$type->id}:{$employee->id}:{$fromYear}");
                    }
                });
                $carried++;
            }
        });
        AuditLog::record('leave.carried_forward', $type, [], [], ['from_year' => $fromYear, 'employees' => $carried]);

        return ['employees' => $carried];
    }

    public function adjust(Employee $employee, LeaveType $type, int $year, float $amount, string $note, User $actor): LeaveLedgerEntry
    {
        $entry = $this->entry($employee, $type, $year, 'adjustment', $amount, now()->toDateString(), $actor, null, $note);
        AuditLog::record('leave.balance_adjusted', $entry, [], ['amount' => $amount], ['employee' => $employee->public_id, 'leave_type' => $type->code, 'year' => $year, 'note' => $note]);

        return $entry;
    }

    private function entry(Employee $employee, LeaveType $type, int $year, string $kind, float $amount, string $on, User $actor, ?string $key, ?string $note = null, ?LeaveRequest $request = null, ?string $expiresOn = null): ?LeaveLedgerEntry
    {
        if ($key && LeaveLedgerEntry::query()->where('idempotency_key', $key)->exists()) {
            return null;
        }
        $entry = new LeaveLedgerEntry(['note' => $note]);
        $entry->forceFill(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_year' => $year, 'entry_type' => $kind, 'amount' => $amount,
            'effective_on' => $on, 'expires_on' => $expiresOn, 'leave_request_id' => $request?->id, 'idempotency_key' => $key, 'created_by' => $actor->id])->save();

        return $entry;
    }
}
