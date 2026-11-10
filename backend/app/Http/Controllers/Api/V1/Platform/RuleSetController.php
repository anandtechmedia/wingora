<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Country\RuleCategory;
use App\Country\RuleWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Resources\RuleVersionResource;
use App\Models\RuleSet;
use App\Models\RuleVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RuleSetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', RuleSet::class);
        $data = $request->validate([
            'country' => ['sometimes', 'string', 'size:2'],
            'category' => ['sometimes', Rule::enum(RuleCategory::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $sets = RuleSet::query()
            ->when($data['country'] ?? null, fn ($q, $c) => $q->where('country_code', $c))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('country_code')->orderBy('category')->orderBy('subdivision_code')->orderBy('key')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json($sets->through(fn (RuleSet $s) => $this->present($s)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', RuleSet::class);
        $country = (string) $request->input('country_code');
        $subdivision = $request->input('subdivision_code');

        $data = $request->validate([
            'country_code' => ['required', 'string', Rule::exists('countries', 'code')->where('is_active', true)],
            'subdivision_code' => ['nullable', 'string', Rule::exists('subdivisions', 'code')->where('country_code', $country)],
            'category' => ['required', Rule::enum(RuleCategory::class)],
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('rule_sets', 'key')
                ->where(fn ($q) => $q->where('country_code', $country)->where('category', $request->input('category'))
                    ->when($subdivision, fn ($q) => $q->where('subdivision_code', $subdivision), fn ($q) => $q->whereNull('subdivision_code')))],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $set = new RuleSet($data);
        $set->forceFill(['created_by' => $request->user()->id])->save();

        return response()->json(['data' => $this->present($set)], 201);
    }

    public function show(RuleSet $ruleSet): JsonResponse
    {
        Gate::authorize('view', $ruleSet);

        return response()->json(['data' => $this->present($ruleSet) + [
            'versions' => RuleVersionResource::collection($ruleSet->versions()->orderByDesc('version')->get()),
        ]]);
    }

    public function storeVersion(Request $request, RuleSet $ruleSet, RuleWorkflow $workflow): JsonResponse
    {
        Gate::authorize('update', $ruleSet);
        $version = $workflow->createDraft($ruleSet, self::validateVersion($request, $ruleSet), $request->user());

        return (new RuleVersionResource($version->load(['ruleSet', 'events.actor'])))->response()->setStatusCode(201);
    }

    /** Payloads are validated per category; only validated keys are stored. */
    public static function validateVersion(Request $request, RuleSet $set): array
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'change_summary' => ['required', 'string', 'max:500'],
            'legal_reference' => ['nullable', 'string', 'max:500'],
        ] + $set->category->payloadRules());

        if ($set->category === RuleCategory::Holidays) {
            foreach ($data['payload']['holidays'] as $i => $holiday) {
                if ($holiday['date'] < $data['effective_from'] || (($data['effective_to'] ?? null) && $holiday['date'] > $data['effective_to'])) {
                    throw ValidationException::withMessages(["payload.holidays.{$i}.date" => 'Holiday dates must fall within the effective period.']);
                }
            }
        }

        return $data + ['effective_to' => null, 'legal_reference' => null];
    }

    private function present(RuleSet $s): array
    {
        return [
            'id' => $s->public_id,
            'country_code' => $s->country_code,
            'subdivision_code' => $s->subdivision_code,
            'jurisdiction' => $s->jurisdiction(),
            'category' => $s->category->value,
            'key' => $s->key,
            'name' => $s->name,
            'description' => $s->description,
            'approved_versions' => $s->versions()->where('status', RuleVersion::APPROVED)->count(),
        ];
    }
}
