<?php

namespace App\Time;

use App\Audit\AuditEvents;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Security\Permission as P;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(private readonly ScheduleResolver $schedules) {}

    public function clockIn(Employee $employee, ?string $note = null, ?CarbonImmutable $now = null): AttendanceRecord
    {
        $now = ($now ?? CarbonImmutable::now())->utc()->startOfMinute();
        $zone = TimeZones::for($employee);
        $date = $now->setTimezone($zone)->toDateString();

        try {
            return DB::transaction(function () use ($employee, $now, $zone, $date, $note) {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
                abort_if(AttendanceRecord::query()->where('open_employee_id', $employee->id)->exists(), 409, __('You are already clocked in.'));
                abort_if(AttendanceRecord::query()->where('employee_id', $employee->id)->where('work_date', $date)->exists(), 409, __('Attendance is already recorded for today. Request a correction instead.'));

                $record = new AttendanceRecord(['note' => $note]);
                $record->forceFill(['employee_id' => $employee->id, 'work_date' => $date, 'timezone' => $zone, 'clock_in_at' => $now, 'open_employee_id' => $employee->id, 'source' => 'clock']);
                $this->applySchedule($record, $employee);
                $this->compute($record);
                $record->save();

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, __('You are already clocked in.'));
        }
    }

    public function clockOut(Employee $employee, ?CarbonImmutable $now = null): AttendanceRecord
    {
        $now = ($now ?? CarbonImmutable::now())->utc()->startOfMinute();

        return DB::transaction(function () use ($employee, $now) {
            $record = AttendanceRecord::query()->where('open_employee_id', $employee->id)->lockForUpdate()->first();
            abort_unless($record, 409, __('You are not clocked in.'));
            abort_if($now->lessThanOrEqualTo($record->clock_in_at), 409, __('Clock-out must be after clock-in.'));
            $record->forceFill(['clock_out_at' => $now, 'open_employee_id' => null]);
            $this->compute($record);
            $record->save();

            return $record;
        });
    }

    /** HR correction or an approved request. Always audited with before/after values and the reason. */
    public function correct(Employee $employee, string $date, CarbonImmutable $in, ?CarbonImmutable $out, string $reason, User $actor, string $source = 'manual', ?AttendanceRequest $request = null): AttendanceRecord
    {
        $zone = TimeZones::for($employee);
        $this->validateTimes($date, $in, $out, $zone, 'clock_in');

        try {
            return DB::transaction(function () use ($employee, $date, $in, $out, $reason, $actor, $source, $request, $zone) {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
                $record = AttendanceRecord::query()->where('employee_id', $employee->id)->where('work_date', $date)->lockForUpdate()->first();
                $before = $record ? $this->snapshot($record) : null;
                if (! $record) {
                    $record = new AttendanceRecord;
                    $record->forceFill(['employee_id' => $employee->id, 'work_date' => $date, 'timezone' => $zone]);
                }
                $record->forceFill(['clock_in_at' => $in, 'clock_out_at' => $out, 'open_employee_id' => $out ? null : $employee->id, 'source' => $source, 'updated_by' => $actor->id, 'note' => mb_substr($reason, 0, 500)]);
                $this->applySchedule($record, $employee);
                $this->compute($record);
                $record->save();

                AuditLog::record($source === 'request' ? AuditEvents::ATTENDANCE_ADJUSTMENT_APPROVED : AuditEvents::ATTENDANCE_ADJUSTED, $record,
                    $before ?? [], $this->snapshot($record), ['reason' => $reason, 'employee' => $employee->public_id, 'work_date' => $date, 'request' => $request?->public_id]);

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['clock_out' => __('The employee has another open attendance record; add a clock-out time.')]);
        }
    }

    public function submitRequest(Employee $employee, string $date, CarbonImmutable $in, ?CarbonImmutable $out, string $reason, User $actor): AttendanceRequest
    {
        $this->validateTimes($date, $in, $out, TimeZones::for($employee), 'clock_in');
        abort_if(AttendanceRequest::query()->where('employee_id', $employee->id)->where('work_date', $date)->where('status', 'pending')->exists(), 409, __('A correction request for this date is already pending.'));

        $request = new AttendanceRequest(['reason' => $reason]);
        $request->forceFill(['employee_id' => $employee->id, 'work_date' => $date, 'clock_in_at' => $in, 'clock_out_at' => $out, 'status' => 'pending', 'requested_by' => $actor->id])->save();

        return $request;
    }

    /** Row lock + status check: two approvers acting at once yield one decision; the second gets 409. */
    public function decide(AttendanceRequest $request, User $actor, bool $approve, ?string $note): AttendanceRequest
    {
        return DB::transaction(function () use ($request, $actor, $approve, $note) {
            $locked = AttendanceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $employee = $locked->employee;
            if (! Approvers::canDecide($actor, $employee, P::AttendanceApprove, P::AttendanceManage)) {
                throw new AuthorizationException;
            }
            abort_unless($locked->status === 'pending', 409, __('This request has already been decided.'));

            $locked->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            if ($approve) {
                $this->correct($employee, $locked->work_date->toDateString(), $locked->clock_in_at, $locked->clock_out_at, $locked->reason, $actor, 'request', $locked);
            } else {
                AuditLog::record('attendance.adjustment_rejected', $locked, metadata: ['employee' => $employee->public_id, 'note' => $note]);
            }

            return $locked;
        });
    }

    public function cancelRequest(AttendanceRequest $request, User $actor): AttendanceRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $locked = AttendanceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($actor->employee?->id !== $locked->employee_id && ! $actor->hasPermission(P::AttendanceManage)) {
                throw new AuthorizationException;
            }
            abort_unless($locked->status === 'pending', 409, __('Only pending requests can be cancelled.'));
            $locked->forceFill(['status' => 'cancelled', 'decided_by' => $actor->id, 'decided_at' => now()])->save();

            return $locked;
        });
    }

    public function compute(AttendanceRecord $record): void
    {
        $in = $record->clock_in_at;
        $out = $record->clock_out_at;
        $start = $record->scheduled_start_at;
        $end = $record->scheduled_end_at;
        $shift = $record->shift_id ? $record->shift : null;

        $late = $start && $shift && TimeZones::minutesBetween($start, $in) > $shift->late_grace_minutes ? TimeZones::minutesBetween($start, $in) : 0;
        $worked = $early = $overtime = 0;
        if ($out) {
            $raw = TimeZones::minutesBetween($in, $out);
            $worked = $raw > $record->break_minutes ? $raw - $record->break_minutes : $raw;
            if ($end && $shift && TimeZones::minutesBetween($out, $end) > $shift->early_grace_minutes) {
                $early = TimeZones::minutesBetween($out, $end);
            }
            if ($start && $end && $shift) {
                $threshold = $shift->overtime_after_minutes ?? max(0, TimeZones::minutesBetween($start, $end) - $shift->break_minutes);
                $overtime = max(0, $worked - $threshold);
            }
        }
        $record->forceFill(['late_minutes' => max(0, $late), 'early_minutes' => $early, 'worked_minutes' => $worked, 'overtime_minutes' => $overtime]);
    }

    private function applySchedule(AttendanceRecord $record, Employee $employee): void
    {
        $date = $record->work_date->toDateString();
        $shift = $this->schedules->shiftFor($employee, $date);
        if ($shift) {
            [$start, $end] = ScheduleResolver::window($shift, $date, $record->timezone);
            $record->forceFill(['shift_id' => $shift->id, 'scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'break_minutes' => $shift->break_minutes]);
            $record->setRelation('shift', $shift);
        } else {
            $record->forceFill(['shift_id' => null, 'scheduled_start_at' => null, 'scheduled_end_at' => null, 'break_minutes' => 0]);
            $record->setRelation('shift', null);
        }
    }

    private function validateTimes(string $date, CarbonImmutable $in, ?CarbonImmutable $out, string $zone, string $field): void
    {
        if ($in->setTimezone($zone)->toDateString() !== $date) {
            throw ValidationException::withMessages([$field => __('Clock-in must fall on the work date in the employee\'s time zone.')]);
        }
        if ($in->isFuture() || $out?->isFuture()) {
            throw ValidationException::withMessages([$field => __('Attendance times cannot be in the future.')]);
        }
        if ($out && ($out->lessThanOrEqualTo($in) || TimeZones::minutesBetween($in, $out) > 24 * 60)) {
            throw ValidationException::withMessages(['clock_out' => __('Clock-out must be after clock-in and within 24 hours.')]);
        }
    }

    private function snapshot(AttendanceRecord $r): array
    {
        return ['clock_in_at' => $r->clock_in_at?->toIso8601String(), 'clock_out_at' => $r->clock_out_at?->toIso8601String(), 'source' => $r->source];
    }
}
