<?php

namespace App\Http\Controllers\Api\V1;

use App\Country\RuleCategory;
use App\Country\RuleResolver;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenantReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\RuleVersionResource;
use App\Http\Resources\WorkLocationResource;
use App\Models\HolidayCalendar;
use App\Models\LegalEntity;
use App\Models\RuleVersion;
use App\Models\WorkLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkLocationController extends Controller
{
    use ResolvesTenantReferences;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WorkLocation::class);
        $data = $request->validate(['legal_entity_id' => ['sometimes', 'string', 'max:26'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $entity = isset($data['legal_entity_id']) ? $this->owned(LegalEntity::class, $data['legal_entity_id'], 'legal_entity_id') : null;

        return WorkLocationResource::collection(WorkLocation::query()->with(['legalEntity', 'holidayCalendar'])
            ->when($entity, fn ($q) => $q->where('legal_entity_id', $entity->id))
            ->orderBy('name')->paginate((int) ($data['per_page'] ?? 25)));
    }

    /** A location takes its country from its legal entity; its subdivision and time zone must belong to that country. */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', WorkLocation::class);
        $request->validate(['legal_entity_id' => ['required', 'string', 'max:26']]);
        $entity = $this->owned(LegalEntity::class, $request->input('legal_entity_id'), 'legal_entity_id');
        if ($entity->status !== 'active') {
            throw ValidationException::withMessages(['legal_entity_id' => 'The legal entity is inactive.']);
        }

        $data = $this->validated($request, $entity->country_code);
        $location = new WorkLocation(collect($data)->except(['holiday_calendar_id', 'legal_entity_id'])->all() + ['country_code' => $entity->country_code]);
        $location->forceFill([
            'legal_entity_id' => $entity->id,
            'holiday_calendar_id' => $this->calendarId($data, $entity->country_code, $data['subdivision_code']),
        ])->save();

        return (new WorkLocationResource($location->load(['legalEntity', 'holidayCalendar'])))->response()->setStatusCode(201);
    }

    public function show(WorkLocation $workLocation): WorkLocationResource
    {
        Gate::authorize('view', $workLocation);

        return new WorkLocationResource($workLocation->load(['legalEntity', 'holidayCalendar']));
    }

    public function update(Request $request, WorkLocation $workLocation): WorkLocationResource
    {
        Gate::authorize('update', $workLocation);
        $data = $this->validated($request, $workLocation->country_code, $workLocation);

        $workLocation->fill(collect($data)->except(['holiday_calendar_id'])->all());
        if (array_key_exists('holiday_calendar_id', $data)) {
            $workLocation->forceFill(['holiday_calendar_id' => $this->calendarId($data, $workLocation->country_code, $workLocation->subdivision_code)]);
        }
        $workLocation->save();

        return new WorkLocationResource($workLocation->load(['legalEntity', 'holidayCalendar']));
    }

    /** Approved rules that apply at this location on a date: country-level and subdivision-level, listed separately. */
    public function rules(Request $request, WorkLocation $workLocation, RuleResolver $resolver): AnonymousResourceCollection
    {
        Gate::authorize('view', $workLocation);
        $data = $request->validate([
            'category' => ['required', Rule::enum(RuleCategory::class)],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return RuleVersionResource::collection($resolver->forLocation($workLocation, RuleCategory::from($data['category']), $data['date'] ?? now()->toDateString()));
    }

    public function holidays(Request $request, WorkLocation $workLocation, RuleResolver $resolver): JsonResponse
    {
        Gate::authorize('view', $workLocation);
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        [$from, $to] = ["{$year}-01-01", "{$year}-12-31"];
        $calendar = $workLocation->holidayCalendar;

        $statutory = ($calendar?->include_statutory ?? true)
            ? $resolver->effectiveBetween($workLocation->country_code, $workLocation->subdivision_code, RuleCategory::Holidays, $from, $to)
                ->flatMap(fn (RuleVersion $v) => collect($v->payload['holidays'])
                    ->filter(fn ($h) => $h['date'] >= max($from, $v->effective_from->toDateString())
                        && $h['date'] <= min($to, $v->effective_to?->toDateString() ?? $to))
                    ->map(fn ($h) => ['date' => $h['date'], 'name' => $h['name'], 'type' => $h['type'], 'paid' => $h['paid'], 'source' => 'statutory', 'jurisdiction' => $v->ruleSet->jurisdiction()]))
            : collect();

        $company = $calendar ? $calendar->holidays()->whereBetween('date', [$from, $to])->get()
            ->map(fn ($h) => ['date' => $h->date->toDateString(), 'name' => $h->name, 'type' => $h->type, 'paid' => $h->is_paid, 'source' => 'company', 'jurisdiction' => null])
            : collect();

        return response()->json(['data' => $statutory->concat($company)->sortBy('date')->values()]);
    }

    private function validated(Request $request, string $country, ?WorkLocation $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'legal_entity_id' => $existing ? ['prohibited'] : ['required'],
            'country_code' => ['prohibited'],
            'name' => [$required, 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('work_locations')->where('tenant_id', $request->user()->tenant_id)->ignore($existing?->id)],
            'subdivision_code' => $existing ? ['prohibited'] : ['required', 'string', Rule::exists('subdivisions', 'code')->where('country_code', $country)],
            'timezone' => [$required, 'string', Rule::exists('country_timezones', 'timezone')->where('country_code', $country)],
            'holiday_calendar_id' => ['nullable', 'string', 'max:26'],
            'city' => ['nullable', 'string', 'max:120'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'is_remote' => ['sometimes', 'boolean'],
            'status' => $existing ? ['sometimes', Rule::in(['active', 'inactive'])] : ['prohibited'],
        ]);
    }

    private function calendarId(array $data, string $country, string $subdivision): ?int
    {
        if (empty($data['holiday_calendar_id'])) {
            return null;
        }
        $calendar = $this->owned(HolidayCalendar::class, $data['holiday_calendar_id'], 'holiday_calendar_id');
        if ($calendar->country_code !== $country || ($calendar->subdivision_code && $calendar->subdivision_code !== $subdivision)) {
            throw ValidationException::withMessages(['holiday_calendar_id' => 'The holiday calendar is for a different jurisdiction.']);
        }

        return $calendar->id;
    }
}
