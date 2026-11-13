<?php

namespace App\Http\Controllers\Api\V1\Performance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewCycle;
use App\Performance\PerformanceAccess;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CycleController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(PerformanceAccess::any($user), 403);
        $data = $request->validate(['status' => ['sometimes', Rule::in(PerformanceReviewCycle::STATUSES)], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = PerformanceReviewCycle::query()
            ->when(! $user->hasPermission(P::PerformanceConfigure), fn ($q) => $q->where('status', '!=', 'draft'))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->withCount(['reviews', 'reviews as completed_count' => fn ($q) => $q->whereIn('status', ['completed', 'acknowledged'])])
            ->orderByDesc('starts_on')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($c) => $this->present($c))->values(), 'meta' => $this->meta($page)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PerformanceConfigure), 403);
        $data = $this->validated($request, true);
        $cycle = new PerformanceReviewCycle;
        $cycle->forceFill(['status' => 'draft', 'version' => 1, 'created_by' => $request->user()->id]);
        $this->fill($cycle, $data);
        $cycle->save();

        return response()->json(['data' => $this->present($cycle->loadCount('reviews'))], 201);
    }

    public function update(Request $request, PerformanceReviewCycle $cycle): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PerformanceConfigure), 403);
        $data = $this->validated($request, false);
        $this->checkVersion($cycle, (int) $data['version']);
        if ($cycle->status !== 'draft' && array_intersect(array_keys($data), ['questions', 'rating_scale', 'starts_on', 'ends_on'])) {
            throw ValidationException::withMessages(['questions' => __('Questions, scale and period can only change before the cycle is launched.')]);
        }
        $this->fill($cycle, $data);
        $cycle->version = $cycle->version + 1;
        $cycle->save();

        return response()->json(['data' => $this->present($cycle->loadCount('reviews'))]);
    }

    /** Opens the cycle and creates one review for each active employee in scope, assigned to their current manager. */
    public function launch(Request $request, PerformanceReviewCycle $cycle): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PerformanceConfigure), 403);
        $data = $request->validate(['version' => ['required', 'integer'], 'department_id' => ['sometimes', 'nullable', 'string', 'max:26'], 'legal_entity_id' => ['sometimes', 'nullable', 'string', 'max:26']]);
        $created = DB::transaction(function () use ($cycle, $data) {
            $c = PerformanceReviewCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            $this->checkVersion($c, (int) $data['version']);
            abort_unless($c->status === 'draft', 422, __('Only a draft cycle can be launched.'));
            $employees = Employee::query()->where('status', 'active')
                ->when($data['department_id'] ?? null, fn ($q, $d) => $q->where('department_id', Department::query()->where('public_id', $d)->value('id') ?? 0))
                ->when($data['legal_entity_id'] ?? null, fn ($q, $l) => $q->where('legal_entity_id', LegalEntity::query()->where('public_id', $l)->value('id') ?? 0))
                ->whereNotIn('id', PerformanceReview::query()->where('review_cycle_id', $c->id)->select('employee_id'));
            $count = 0;
            $now = now();
            $employees->select(['id', 'manager_id'])->chunkById(500, function ($chunk) use ($c, $now, &$count) {
                DB::table('performance_reviews')->insert($chunk->map(fn ($e) => ['public_id' => (string) Str::ulid(), 'tenant_id' => $c->tenant_id, 'review_cycle_id' => $c->id,
                    'employee_id' => $e->id, 'manager_employee_id' => $e->manager_id, 'status' => 'pending_self', 'version' => 1, 'created_at' => $now, 'updated_at' => $now])->all());
                $count += $chunk->count();
            });
            abort_if($count === 0, 422, __('No active employees match this cycle.'));
            $c->forceFill(['status' => 'open', 'version' => $c->version + 1])->saveQuietly();
            AuditLog::record('performance.review_cycle_launched', $c, ['status' => 'draft'], ['status' => 'open', 'reviews_created' => $count]);

            return $count;
        });

        return response()->json(['data' => $this->present($cycle->fresh()->loadCount('reviews')), 'reviews_created' => $created]);
    }

    public function close(Request $request, PerformanceReviewCycle $cycle): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PerformanceConfigure), 403);
        $data = $request->validate(['version' => ['required', 'integer']]);
        $this->checkVersion($cycle, (int) $data['version']);
        abort_unless($cycle->status === 'open', 422, __('Only an open cycle can be closed.'));
        $cycle->forceFill(['status' => 'closed', 'version' => $cycle->version + 1])->save();

        return response()->json(['data' => $this->present($cycle->loadCount('reviews'))]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'version' => [$creating ? 'prohibited' : 'required', 'integer'],
            'name' => [$req, 'string', 'max:120'],
            'starts_on' => [$req, 'date'],
            'ends_on' => [$req, 'date', 'after_or_equal:starts_on'],
            'self_due_on' => ['sometimes', 'nullable', 'date'],
            'manager_due_on' => ['sometimes', 'nullable', 'date'],
            'rating_scale' => ['sometimes', 'integer', Rule::in([3, 4, 5, 10])],
            'questions' => [$req, 'array', 'min:1', 'max:20'],
            'questions.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'questions.*.label' => ['required', 'string', 'max:300'],
            'questions.*.type' => ['required', Rule::in(['text', 'rating'])],
        ]);
        if (isset($data['questions'])) {
            $data['questions'] = array_map(fn ($q) => ['key' => $q['key'], 'label' => $q['label'], 'type' => $q['type']], array_values($data['questions']));
        }

        return $data;
    }

    private function fill(PerformanceReviewCycle $cycle, array $data): void
    {
        foreach (['name', 'starts_on', 'ends_on', 'self_due_on', 'manager_due_on', 'rating_scale', 'questions'] as $f) {
            if (array_key_exists($f, $data)) {
                $cycle->{$f} = $data[$f];
            }
        }
    }

    private function present(PerformanceReviewCycle $c): array
    {
        return ['id' => $c->public_id, 'name' => $c->name, 'status' => $c->status, 'starts_on' => $c->starts_on?->toDateString(), 'ends_on' => $c->ends_on?->toDateString(),
            'self_due_on' => $c->self_due_on?->toDateString(), 'manager_due_on' => $c->manager_due_on?->toDateString(), 'rating_scale' => $c->rating_scale ?? 5,
            'questions' => $c->questions, 'review_count' => $c->reviews_count ?? null, 'completed_count' => $c->completed_count ?? null, 'version' => $c->version];
    }
}
