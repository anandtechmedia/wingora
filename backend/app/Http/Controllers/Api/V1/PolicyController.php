<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\StoredFile;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Company policies and acknowledgements. Everyone in the tenant reads published policies; HR manages them. */
class PolicyController extends Controller
{
    use Concerns\ResolvesTenantReferences;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $manage = $user->hasPermission(P::PoliciesManage);
        $policies = Policy::query()->when(! $manage, fn ($q) => $q->where('status', 'published'))
            ->withCount('acknowledgements')->orderByDesc('effective_date')->orderBy('title')->limit(200)->get();
        $acknowledged = $user->employee
            ? PolicyAcknowledgement::query()->where('employee_id', $user->employee->id)->pluck('acknowledged_at', 'policy_id')
            : collect();

        return response()->json(['data' => $policies->map(fn (Policy $p) => $this->present($p, $acknowledged[$p->id] ?? null, $manage))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'version' => ['required', 'string', 'max:20'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'file_id' => ['nullable', 'string', 'max:26'],
        ]);
        if (Policy::query()->where('title', $data['title'])->where('version', $data['version'])->exists()) {
            throw ValidationException::withMessages(['version' => __('This policy version already exists.')]);
        }
        $policy = new Policy($data);
        $policy->forceFill([
            'created_by' => $request->user()->id,
            'file_id' => isset($data['file_id']) ? $this->owned(StoredFile::class, $data['file_id'], 'file_id')->id : null,
        ])->save();

        return response()->json(['data' => $this->present($policy, null, true)], 201);
    }

    public function publish(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless($policy->status === 'draft', 409, __('Only draft policies can be published.'));
        $policy->forceFill(['status' => 'published', 'published_at' => now()])->save();

        return response()->json(['data' => $this->present($policy, null, true)]);
    }

    public function retire(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless($policy->status === 'published', 409, __('Only published policies can be retired.'));
        $policy->forceFill(['status' => 'retired'])->save();

        return response()->json(['data' => $this->present($policy, null, true)]);
    }

    /** The signed-in employee acknowledges a published policy (idempotent). */
    public function acknowledge(Request $request, Policy $policy): JsonResponse
    {
        $employee = $request->user()->employee ?? abort(403);
        abort_unless($policy->status === 'published', 409, __('Only published policies can be acknowledged.'));
        $ack = PolicyAcknowledgement::query()->where('policy_id', $policy->id)->where('employee_id', $employee->id)->first() ?? new PolicyAcknowledgement;
        if (! $ack->exists) {
            $ack->forceFill(['policy_id' => $policy->id, 'employee_id' => $employee->id, 'acknowledged_at' => now(), 'ip_address' => $request->ip()])->save();
        }

        return response()->json(['data' => ['policy_id' => $policy->public_id, 'acknowledged_at' => $ack->acknowledged_at->toIso8601String()]]);
    }

    /** Published policies with this employee's acknowledgement status. */
    public function forEmployee(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);
        $acks = $employee->policyAcknowledgements()->pluck('acknowledged_at', 'policy_id');

        return response()->json(['data' => Policy::query()->where('status', 'published')->orderByDesc('effective_date')->limit(200)->get()
            ->map(fn (Policy $p) => $this->present($p, $acks[$p->id] ?? null, false))]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::PoliciesManage), 403);
    }

    private function present(Policy $p, mixed $acknowledgedAt, bool $manage): array
    {
        return array_filter([
            'id' => $p->public_id, 'title' => $p->title, 'version' => $p->version, 'summary' => $p->summary, 'status' => $p->status,
            'effective_date' => $p->effective_date->toDateString(), 'published_at' => $p->published_at?->toIso8601String(),
            'file_id' => $p->file?->public_id,
            'acknowledged_at' => $acknowledgedAt ? Carbon::parse($acknowledgedAt)->toIso8601String() : null,
            'acknowledgements_count' => $manage ? ($p->acknowledgements_count ?? $p->acknowledgements()->count()) : null,
        ], fn ($v, $k) => $v !== null || in_array($k, ['summary', 'published_at', 'acknowledged_at', 'file_id'], true), ARRAY_FILTER_USE_BOTH);
    }
}
