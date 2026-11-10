<?php

namespace App\Time;

use App\Country\RuleCategory;
use App\Country\RuleResolver;
use App\Models\RuleVersion;
use App\Models\WorkLocation;
use Illuminate\Support\Collection;

/** Holidays for a work location: approved statutory holidays for its jurisdiction plus the company calendar. */
class HolidayService
{
    public const NON_WORKING_TYPES = ['public', 'company'];

    public function __construct(private readonly RuleResolver $resolver) {}

    public function forLocation(WorkLocation $location, string $from, string $to): Collection
    {
        $calendar = $location->holidayCalendar;

        $statutory = ($calendar?->include_statutory ?? true)
            ? $this->resolver->effectiveBetween($location->country_code, $location->subdivision_code, RuleCategory::Holidays, $from, $to)
                ->flatMap(fn (RuleVersion $v) => collect($v->payload['holidays'])
                    ->filter(fn ($h) => $h['date'] >= max($from, $v->effective_from->toDateString())
                        && $h['date'] <= min($to, $v->effective_to?->toDateString() ?? $to))
                    ->map(fn ($h) => ['date' => $h['date'], 'name' => $h['name'], 'type' => $h['type'], 'paid' => $h['paid'], 'source' => 'statutory', 'jurisdiction' => $v->ruleSet->jurisdiction()]))
            : collect();

        $company = $calendar ? $calendar->holidays()->where('date', '>=', $from)->where('date', '<', date('Y-m-d', strtotime($to.' +1 day')))->get()
            ->map(fn ($h) => ['date' => $h->date->toDateString(), 'name' => $h->name, 'type' => $h->type, 'paid' => $h->is_paid, 'source' => 'company', 'jurisdiction' => null])
            : collect();

        return $statutory->concat($company)->sortBy('date')->values();
    }

    /** @return array<string, true> Dates that are days off (optional holidays and observances are not). */
    public function daysOff(?WorkLocation $location, string $from, string $to): array
    {
        if ($location === null) {
            return [];
        }

        return $this->forLocation($location, $from, $to)->filter(fn ($h) => in_array($h['type'], self::NON_WORKING_TYPES, true))
            ->mapWithKeys(fn ($h) => [$h['date'] => true])->all();
    }
}
