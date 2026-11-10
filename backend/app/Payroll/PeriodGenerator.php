<?php

namespace App\Payroll;

use App\Models\PayrollCalendar;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;

/** Generates consecutive pay periods for a calendar. Semimonthly periods are the 1st–15th and the 16th–month end. */
final class PeriodGenerator
{
    /** @return array{0: string, 1: string} start and end dates of the period that begins on `$start` */
    public static function bounds(string $frequency, string $start): array
    {
        $s = CarbonImmutable::parse($start);
        $end = match ($frequency) {
            'weekly' => $s->addDays(6),
            'biweekly' => $s->addDays(13),
            'monthly' => $s->addMonthNoOverflow()->subDay(),
            'semimonthly' => $s->day === 1 ? $s->setDay(15) : $s->endOfMonth(),
        };

        return [$s->toDateString(), $end->toDateString()];
    }

    /** @return list<PayrollPeriod> */
    public function generate(PayrollCalendar $calendar, int $count): array
    {
        $last = $calendar->periods()->reorder('start_date', 'desc')->first();
        $start = $last ? CarbonImmutable::parse($last->end_date?->toDateString())->addDay()->toDateString() : $calendar->first_period_start?->toDateString();
        $created = [];
        for ($i = 0; $i < $count; $i++) {
            [$from, $to] = self::bounds($calendar->pay_frequency, $start);
            $period = new PayrollPeriod;
            $period->forceFill(['payroll_calendar_id' => $calendar->id, 'start_date' => $from, 'end_date' => $to,
                'pay_date' => CarbonImmutable::parse($to)->addDays($calendar->pay_date_offset_days)->toDateString()]);
            $period->save();
            $created[] = $period;
            $start = CarbonImmutable::parse($to)->addDay()->toDateString();
        }

        return $created;
    }
}
