<?php

namespace App\Time;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Resolves an employee's effective schedule and shift. Without any schedule, Monday–Friday are working days. */
class ScheduleResolver
{
    /** @var array<int, ?WorkSchedule> */
    private array $schedules = [];

    private ?WorkSchedule $default = null;

    private bool $defaultLoaded = false;

    /** @var array<int, ?Shift> */
    private array $shifts = [];

    /** @var array<int, Collection<int, ShiftAssignment>> preloaded assignments overlapping a date range, newest first */
    private array $assignments = [];

    /** @var array{0: string, 1: string}|null */
    private ?array $preloadedRange = null;

    /**
     * Loads the shift assignments of many employees for a date range in one query (reports);
     * lookups inside that range then read from memory instead of querying per employee and day.
     *
     * @param  iterable<Employee>  $employees
     */
    public function preload(iterable $employees, string $from, string $to): void
    {
        $ids = collect($employees)->pluck('id')->all();
        $rows = ShiftAssignment::query()->whereIn('employee_id', $ids)->where('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->orderByDesc('effective_from')->get()->groupBy('employee_id');
        $this->assignments = [];
        foreach ($ids as $id) {
            $this->assignments[$id] = $rows->get($id, collect());
        }
        $this->preloadedRange = [$from, $to];
    }

    public function scheduleFor(Employee $employee, string $date): ?WorkSchedule
    {
        $assignment = $this->preloadedRange !== null && isset($this->assignments[$employee->id]) && $date >= $this->preloadedRange[0] && $date <= $this->preloadedRange[1]
            ? $this->assignments[$employee->id]->first(fn (ShiftAssignment $a) => self::day($a->effective_from) <= $date && ($a->effective_to === null || self::day($a->effective_to) >= $date))
            : ShiftAssignment::query()->where('employee_id', $employee->id)->covering($date)->orderByDesc('effective_from')->first();
        if ($assignment) {
            return $this->schedules[$assignment->work_schedule_id] ??= WorkSchedule::query()->find($assignment->work_schedule_id);
        }

        if (! $this->defaultLoaded) {
            $this->default = WorkSchedule::query()->where('is_default', true)->where('status', 'active')->first();
            $this->defaultLoaded = true;
        }

        return $this->default;
    }

    private static function day(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
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
