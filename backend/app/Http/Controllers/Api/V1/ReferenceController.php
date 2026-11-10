<?php

namespace App\Http\Controllers\Api\V1;

use App\Country\RuleCategory;
use App\Country\RuleResolver;
use App\Http\Controllers\Controller;
use App\Http\Resources\RuleVersionResource;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Language;
use App\Models\LocaleFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Read-only global reference data and approved country rules for any authenticated user. */
class ReferenceController extends Controller
{
    public function countries(): JsonResponse
    {
        $countries = Country::query()->where('is_active', true)->with('languages')->orderBy('name')->get();

        return response()->json(['data' => $countries->map(fn (Country $c) => $this->country($c))]);
    }

    public function showCountry(Country $country): JsonResponse
    {
        abort_unless($country->is_active, 404);
        $country->load(['languages', 'subdivisions' => fn ($q) => $q->orderBy('name'), 'timezones', 'locales']);

        return response()->json(['data' => $this->country($country) + [
            'subdivisions' => $country->subdivisions->map->only(['code', 'name', 'type']),
            'timezones' => $country->timezones->pluck('timezone'),
            'locales' => $country->locales->map(fn (LocaleFormat $l) => $this->locale($l)),
        ]]);
    }

    public function currencies(): JsonResponse
    {
        return response()->json(['data' => Currency::query()->where('is_active', true)->orderBy('code')->get()
            ->map->only(['code', 'name', 'symbol', 'minor_units'])]);
    }

    public function languages(): JsonResponse
    {
        return response()->json(['data' => Language::query()->orderBy('code')->get()
            ->map->only(['code', 'name', 'native_name', 'direction', 'status'])]);
    }

    public function locales(): JsonResponse
    {
        return response()->json(['data' => LocaleFormat::query()->with('language')->orderBy('code')->get()
            ->map(fn (LocaleFormat $l) => $this->locale($l))]);
    }

    public function rules(Request $request, Country $country, RuleResolver $resolver): AnonymousResourceCollection
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(RuleCategory::class)],
            'subdivision' => ['nullable', 'string', Rule::exists('subdivisions', 'code')->where('country_code', $country->code)],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return RuleVersionResource::collection($resolver->effective(
            $country->code, $data['subdivision'] ?? null, RuleCategory::from($data['category']), $data['date'] ?? now()->toDateString(),
        ));
    }

    private function country(Country $c): array
    {
        return [
            'code' => $c->code,
            'iso3' => $c->iso3,
            'name' => $c->name,
            'default_currency_code' => $c->default_currency_code,
            'default_language_code' => $c->default_language_code,
            'default_timezone' => $c->default_timezone,
            'subdivision_label' => $c->subdivision_label,
            'languages' => $c->languages->map(fn (Language $l) => ['code' => $l->code, 'official' => (bool) $l->pivot->is_official, 'status' => $l->status]),
        ];
    }

    private function locale(LocaleFormat $l): array
    {
        return $l->only(['code', 'language_code', 'country_code', 'date_format', 'time_format', 'decimal_separator', 'group_separator', 'first_day_of_week'])
            + ['direction' => $l->language->direction, 'active' => $l->language->isActive()];
    }
}
