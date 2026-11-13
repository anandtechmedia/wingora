<?php

namespace App\Http\Controllers\Api\V1\Performance;

use App\Http\Controllers\Controller;
use App\Models\PerformanceCheckIn;
use App\Models\PerformanceKeyResult;
use App\Models\PerformanceObjective;
use App\Models\PerformanceReviewCycle;
use App\Performance\PerformanceAccess;
use App\Performance\Progress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ObjectiveController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(PerformanceAccess::any($user), 403);
        $data = $request->validate([
            'scope' => ['sometimes', 'in:mine,team,all'],
            'employee_id' => ['sometimes', 'string', 'max:26'],
            'status' => ['sometimes', Rule::in(PerformanceObjective::STATUSES)],
            'kind' => ['sometimes', Rule::in(PerformanceObjective::KINDS)],
            'cycle_id' => ['sometimes', 'string', 'max:26'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = $this->scoped(PerformanceObjective::query(), $request, $data['scope'] ?? 'all')
            ->with(['employee', 'parent', 'cycle'])->withCount('keyResults')
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->whereIn('employee_id', PerformanceAccess::employees($user)->where('public_id', $id)->select('employees.id')))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($data['cycle_id'] ?? null, fn ($q, $c) => $q->whereIn('review_cycle_id', PerformanceReviewCycle::query()->where('public_id', $c)->select('id')))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where('title', 'like', '%'.addcslashes($s, '%_\\').'%'));
        $page = $query->orderByRaw("status = 'active' desc")->orderBy('due_on')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($o) => $this->present($o, $request))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, PerformanceObjective $objective): JsonResponse
    {
        $this->visible($request, $objective);

        return response()->json(['data' => $this->detail($objective, $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(PerformanceAccess::any($user), 403);
        $data = $this->validated($request, true);
        $employee = isset($data['employee_id']) ? $this->visibleEmployee($request, $data['employee_id']) : $this->self($request);
        abort_unless(PerformanceAccess::canEditObjectives($user, $employee), 403);
        $objective = DB::transaction(function () use ($data, $employee, $user) {
            $o = new PerformanceObjective;
            $o->forceFill(['employee_id' => $employee->id, 'kind' => $data['kind'], 'status' => $data['status'] ?? 'active', 'created_by' => $user->id]);
            $this->fill($o, $data, $user);
            $o->save();
            $this->syncKeyResults($o, $data['key_results'] ?? []);
            $o->forceFill(['progress' => Progress::objective($o)])->saveQuietly();

            return $o;
        });

        return response()->json(['data' => $this->detail($objective->fresh(), $request)], 201);
    }

    public function update(Request $request, PerformanceObjective $objective): JsonResponse
    {
        $user = $request->user();
        $this->visible($request, $objective);
        abort_unless(PerformanceAccess::canEditObjectives($user, $objective->employee), 403);
        $data = $this->validated($request, false);
        DB::transaction(function () use ($objective, $data, $user) {
            $o = PerformanceObjective::query()->lockForUpdate()->findOrFail($objective->id);
            $this->checkVersion($o, (int) $data['version']);
            $this->fill($o, $data, $user);
            if (isset($data['status'])) {
                $o->status = $data['status'];
                $o->completed_at = $data['status'] === 'completed' ? ($o->completed_at ?? now()) : null;
            }
            if (array_key_exists('key_results', $data)) {
                $this->syncKeyResults($o, $data['key_results']);
            }
            if ($o->kind === 'objective' && isset($data['progress'])) {
                $o->progress = $data['progress'];
            }
            $o->progress = Progress::objective($o);
            $o->version = $o->version + 1;
            $o->save();
        });

        return response()->json(['data' => $this->detail($objective->fresh(), $request)]);
    }

    public function checkIn(Request $request, PerformanceObjective $objective): JsonResponse
    {
        $user = $request->user();
        $this->visible($request, $objective);
        abort_unless(PerformanceAccess::canEditObjectives($user, $objective->employee), 403);
        abort_unless($objective->status === 'active', 422, __('Only active objectives take check-ins.'));
        $data = $request->validate([
            'key_result_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'value' => ['required_with:key_result_id', 'nullable', 'numeric', 'between:-999999999999,999999999999'],
            'progress' => ['required_without:key_result_id', 'nullable', 'integer', 'between:0,100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($objective, $data, $user) {
            $o = PerformanceObjective::query()->lockForUpdate()->findOrFail($objective->id);
            $kr = null;
            if (! empty($data['key_result_id'])) {
                $kr = $o->keyResults()->where('public_id', $data['key_result_id'])->firstOr(fn () => throw ValidationException::withMessages(['key_result_id' => __('Choose a key result of this objective.')]));
                $kr->forceFill(['current_value' => $data['value']])->save();
            } elseif ($o->keyResults()->exists()) {
                throw ValidationException::withMessages(['key_result_id' => __('Check in against one of the key results.')]);
            } else {
                $o->progress = $data['progress'];
            }
            $o->progress = Progress::objective($o);
            $o->version = $o->version + 1;
            $o->save();
            $c = new PerformanceCheckIn;
            $c->forceFill(['objective_id' => $o->id, 'key_result_id' => $kr?->id, 'value' => $kr ? $data['value'] : null, 'progress' => $kr ? Progress::keyResult($kr) : $o->progress,
                'note' => $data['note'] ?? null, 'created_by' => $user->id])->save();
        });

        return response()->json(['data' => $this->detail($objective->fresh(), $request)], 201);
    }

    private function visible(Request $request, PerformanceObjective $objective): void
    {
        abort_unless(PerformanceAccess::any($request->user()) && PerformanceAccess::canView($request->user(), $objective->employee), 404);
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'version' => [$creating ? 'prohibited' : 'required', 'integer'],
            'employee_id' => [$creating ? 'sometimes' : 'prohibited', 'string', 'max:26'],
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in(PerformanceObjective::KINDS)],
            'title' => [$req, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(PerformanceObjective::STATUSES)],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'due_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'cycle_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'key_results' => [$creating && $request->input('kind') === 'okr' ? 'required' : 'sometimes', 'array', $request->input('kind') === 'okr' ? 'min:1' : 'min:0', 'max:10'],
            'key_results.*.id' => ['sometimes', 'string', 'max:26'],
            'key_results.*.title' => ['required', 'string', 'max:200'],
            'key_results.*.start_value' => ['sometimes', 'numeric', 'between:-999999999999,999999999999'],
            'key_results.*.target_value' => ['required', 'numeric', 'between:-999999999999,999999999999'],
            'key_results.*.unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'key_results.*.weight' => ['sometimes', 'integer', 'between:1,10'],
        ]);
    }

    private function fill(PerformanceObjective $o, array $data, $user): void
    {
        foreach (['title', 'description', 'starts_on', 'due_on'] as $f) {
            if (array_key_exists($f, $data)) {
                $o->{$f} = $data[$f];
            }
        }
        if (array_key_exists('parent_id', $data)) {
            $o->parent_id = $data['parent_id'] === null ? null : $this->parent($o, $data['parent_id'], $user);
        }
        if (array_key_exists('cycle_id', $data)) {
            $o->review_cycle_id = $data['cycle_id'] === null ? null
                : PerformanceReviewCycle::query()->where('public_id', $data['cycle_id'])->value('id') ?? throw ValidationException::withMessages(['cycle_id' => __('Choose an existing review cycle.')]);
        }
    }

    /** A parent must be an objective the user can see, and aligning must not create a loop. */
    private function parent(PerformanceObjective $o, string $publicId, $user): int
    {
        $parent = PerformanceObjective::query()->where('public_id', $publicId)
            ->whereIn('employee_id', PerformanceAccess::employees($user)->select('employees.id'))->first();
        $fail = fn () => throw ValidationException::withMessages(['parent_id' => __('Choose an objective you can see, other than this one or one aligned under it.')]);
        if (! $parent) {
            $fail();
        }
        for ($cursor = $parent, $depth = 0; $cursor; $cursor = $cursor->parent, $depth++) {
            if (($o->exists && $cursor->id === $o->id) || $depth > 20) {
                $fail();
            }
        }

        return $parent->id;
    }

    private function syncKeyResults(PerformanceObjective $o, array $rows): void
    {
        $existing = $o->keyResults()->get()->keyBy('public_id');
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $kr = isset($row['id']) ? ($existing[$row['id']] ?? throw ValidationException::withMessages(["key_results.$i.id" => __('Unknown key result.')])) : new PerformanceKeyResult;
            $kr->forceFill(['objective_id' => $o->id, 'title' => $row['title'], 'target_value' => $row['target_value'], 'unit' => $row['unit'] ?? null,
                'weight' => $row['weight'] ?? 1, 'sort_order' => $i] + (isset($row['start_value']) || ! $kr->exists ? ['start_value' => $row['start_value'] ?? 0] : []));
            if (! $kr->exists) {
                $kr->current_value = $kr->start_value;
            }
            $kr->save();
            $keep[] = $kr->id;
        }
        $o->keyResults()->whereNotIn('id', $keep)->get()->each->delete();
    }

    private function present(PerformanceObjective $o, Request $request): array
    {
        $user = $request->user();

        return ['id' => $o->public_id, 'title' => $o->title, 'description' => $o->description, 'kind' => $o->kind, 'status' => $o->status, 'progress' => $o->progress,
            'starts_on' => $o->starts_on?->toDateString(), 'due_on' => $o->due_on?->toDateString(), 'completed_at' => $o->completed_at?->toIso8601String(),
            'employee' => $this->person($o->employee), 'parent' => $o->parent ? ['id' => $o->parent->public_id, 'title' => $o->parent->title] : null,
            'cycle' => $o->cycle ? ['id' => $o->cycle->public_id, 'name' => $o->cycle->name] : null,
            'key_result_count' => $o->key_results_count ?? $o->keyResults()->count(), 'version' => $o->version,
            'can_edit' => PerformanceAccess::canEditObjectives($user, $o->employee), 'updated_at' => $o->updated_at?->toIso8601String()];
    }

    private function detail(PerformanceObjective $o, Request $request): array
    {
        $o->load(['employee', 'parent', 'cycle', 'keyResults']);
        $checkIns = $o->checkIns()->with(['author', 'keyResult'])->latest('id')->limit(20)->get();

        return $this->present($o, $request) + [
            'key_results' => $o->keyResults->map(fn ($kr) => ['id' => $kr->public_id, 'title' => $kr->title, 'start_value' => $kr->start_value, 'target_value' => $kr->target_value,
                'current_value' => $kr->current_value, 'unit' => $kr->unit, 'weight' => $kr->weight, 'progress' => Progress::keyResult($kr)])->values(),
            'check_ins' => $checkIns->map(fn ($c) => ['id' => $c->public_id, 'key_result' => $c->keyResult?->title, 'value' => $c->value, 'progress' => $c->progress,
                'note' => $c->note, 'author' => $c->author?->name, 'created_at' => $c->created_at?->toIso8601String()])->values(),
        ];
    }
}
