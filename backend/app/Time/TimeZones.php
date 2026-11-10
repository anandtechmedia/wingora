<?php

namespace App\Time;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class TimeZones
{
    /** The employee's own zone, else their work location's, else UTC. */
    public static function for(Employee $employee): string
    {
        return $employee->timezone ?: ($employee->workLocation?->timezone ?: 'UTC');
    }

    public static function today(Employee $employee, ?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(self::for($employee))->toDateString();
    }

    /**
     * Parses a local "Y-m-d H:i" wall time in the given zone. Times skipped by a daylight-saving change do not
     * exist and are rejected rather than silently shifted. Repeated (fall-back) times resolve to the first occurrence.
     */
    public static function parseLocal(string $value, string $zone, string $field): CarbonImmutable
    {
        $time = CarbonImmutable::createFromFormat('Y-m-d H:i', $value, $zone);
        if ($time === false || $time->format('Y-m-d H:i') !== $value) {
            throw ValidationException::withMessages([$field => __('This local time does not exist in :zone (daylight saving change).', ['zone' => $zone])]);
        }

        return $time->utc();
    }

    public static function minutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
    }
}
