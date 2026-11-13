<?php

namespace App\Country;

use App\Models\RuleVersion;
use App\Models\WorkLocation;
use Illuminate\Support\Collection;

/**
 * Finds the approved rules for a jurisdiction on a date. Country-level and subdivision-level rule sets are
 * returned side by side (never merged into one "national" rule), so modules can apply the stricter or more
 * specific rule explicitly.
 */
class RuleResolver
{
    /** @return Collection<int, RuleVersion> */
    public function effective(string $country, ?string $subdivision, RuleCategory $category, string $date): Collection
    {
        return $this->query($country, $subdivision, $category)->effectiveOn($date)->get()
            ->sortBy(fn (RuleVersion $v) => [$v->ruleSet->subdivision_code !== null, $v->ruleSet->key])->values();
    }

    /** @return Collection<int, RuleVersion> */
    public function effectiveBetween(string $country, ?string $subdivision, RuleCategory $category, string $from, string $to): Collection
    {
        return $this->query($country, $subdivision, $category)->effectiveBetween($from, $to)->get();
    }

    public function forLocation(WorkLocation $location, RuleCategory $category, string $date): Collection
    {
        return $this->effective($location->country_code, $location->subdivision_code, $category, $date);
    }

    /** @return list<string> Employment types approved for the location's jurisdiction; empty means none approved (fail closed). */
    public function employmentTypes(WorkLocation $location, string $date): array
    {
        return array_values(array_unique($this->forLocation($location, RuleCategory::EmploymentTypes, $date)
            ->flatMap(fn (RuleVersion $v) => RuleCategory::EmploymentTypes->entryKeys($v->payload))->all()));
    }

    private function query(string $country, ?string $subdivision, RuleCategory $category)
    {
        return RuleVersion::query()->with('ruleSet')->whereHas('ruleSet', fn ($q) => $q
            ->where('country_code', $country)
            ->where('category', $category->value)
            ->where(fn ($q) => $q->whereNull('subdivision_code')->when($subdivision, fn ($q) => $q->orWhere('subdivision_code', $subdivision))));
    }
}
