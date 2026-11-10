<?php

namespace App\Time;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/** Resolves an employee's effective schedule and shift. Without any schedule, Monday–Friday are working days. */
class ScheduleResolver
{
    /** @var array<int, ?WorkSchedule> */
    private array $schedules = [];

    private ?WorkSchedule $default = null;

    private bool $defaultLoaded = false;

    /** @var array<int, ?Shift> */
    private array $shifts = [];

    public function scheduleFor(Employee $employee, string $date): ?WorkSchedule
    {
        $assignment = ShiftAssignment::query()->where('employee_id', $employee->id)->covering($date)->orderByDesc('effective_from')->first();
        if ($assignment) {
            return $this->schedules[$assignment->work_schedule_id] ??= WorkSchedule::query()->find($assignment->work_schedule_id);
        }

        if (! $this->defaultLoaded) {
            $this->default = WorkSchedule::query()->where('is_default', true)->where('status', 'active')->first();
            $this->defaultLoaded = true;
        }

        return $this->default;
    }

    public function shiftFor(Employee $employee, string $date): ?Shift
    {
        $id = $this->scheduleFor($employee, $date)?->shiftIdFor(CarbonImmutable::parse($date)->dayOfWeekIso);

        return $id === null ? null : ($this->shifts[$id] ??= Shift::query()->find($id));
    }

    public function isWorkingDay(Employee $employee, string $date): bool
    {
        $schedule = $this->scheduleFor($employee, $date);
        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;

        return $schedule ? $schedule->shiftIdFor($weekday) !== null : $weekday <= 5;
    }

    /**
     * Scheduled start and end as UTC instants. The wall times are placed on the local calendar first, so a
     * 22:00–06:00 shift spanning a daylight-saving change lasts 7 or 9 real hours.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(Shift $shift, string $date, string $zone): array
    {
        $start = CarbonImmutable::parse("{$date} {$shift->start_time}", $zone);
        $endDate = $shift->end_time <= $shift->start_time ? CarbonImmutable::parse($date)->addDay()->toDateString() : $date;
        $end = CarbonImmutable::parse("{$endDate} {$shift->end_time}", $zone);

        return [$start->utc(), $end->utc()];
    }
}
