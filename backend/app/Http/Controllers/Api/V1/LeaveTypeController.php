<?php

namespace App\Http\Controllers\Api\V1;

use App\Country\RuleCategory;
use App\Country\RuleResolver;
use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\LegalEntity;
use App\Security\Permission as P;
use App\Time\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Leave types, effective-dated policies, allocation and carry-forward runs. Writes need leave.configure. */
class LeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->hasPermission(P::LeaveView) || $u->hasPermission(P::LeaveConfigure) || $u->hasPermission(P::LeaveManage), 403);
        $types = LeaveType::query()->when(! $u->hasPermission(P::LeaveConfigure), fn ($q) => $q->where('status', 'active'))->orderBy('name')->get();

        return response()->json(['data' => $types->map($this->present(...))]);
    }

    public function store(Request $request, RuleResolver $rules): JsonResponse
    {
        $this->configure($request);
        $type = new LeaveType($this->validated($request, $rules));
        $type->save();

        return response()->json(['data' => $this->present($type)], 201);
    }

    public function update(Request $request, LeaveType $leaveType, RuleResolver $rules): JsonResponse
    {
        $this->configure($request);
        $leaveType->update($this->validated($request, $rules, $leaveType));

        return response()->json(['data' => $this->present($leaveType)]);
    }

    public function policies(Request $request, LeaveType $leaveType): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->hasPermission(P::LeaveConfigure) || $u->hasPermission(P::LeaveManage), 403);

        return response()->json(['data' => $leaveType->policies()->with('legalEntity')->get()->map($this->policy(...))]);
    }

    /** Policies for the same type and scope may not overlap; a new open-ended policy closes the previous open one. */
    public function storePolicy(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->configure($request);
        $data = $request->validate([
            'legal_entity_id' => ['nullable', 'string', 'max:26'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'annual_entitlement' => ['required', 'numeric', 'between:0,366', 'multiple_of:0.25'],
            'accrual' => ['required', Rule::in(LeavePolicy::ACCRUALS)],
            'prorate_new_joiners' => ['sometimes', 'boolean'],
            'carry_forward_max' => ['sometimes', 'numeric', 'between:0,366'],
            'carry_forward_expiry_months' => ['nullable', 'integer', 'between:1,24'],
            'allow_negative' => ['sometimes', 'boolean'],
            'max_consecutive_days' => ['nullable', 'integer', 'between:1,366'],
            'min_notice_days' => ['sometimes', 'integer', 'between:0,365'],
        ]);
        $entity = null;
        if (! empty($data['legal_entity_id'])) {
            $entity = LegalEntity::query()->where('public_id', $data['legal_entity_id'])->first()
                ?? throw ValidationException::withMessages(['legal_entity_id' => __('Unknown legal entity.')]);
            if ($leaveType->country_code && $entity->country_code !== $leaveType->country_code) {
                throw ValidationException::withMessages(['legal_entity_id' => __('The legal entity is not in this leave type\'s country.')]);
            }
        }

        $policy = DB::transaction(function () use ($leaveType, $data, $entity, $request) {
            LeaveType::query()->whereKey($leaveType->id)->lockForUpdate()->first();
            $scope = fn ($q) => $q->where('leave_type_id', $leaveType->id)->where('legal_entity_id', $entity?->id);
            $from = $data['effective_from'];
            $to = $data['effective_to'] ?? null;
            LeavePolicy::query()->where($scope)->whereNull('effective_to')->where('effective_from', '<', $from)->get()
                ->each(fn ($p) => $p->forceFill(['effective_to' => date('Y-m-d', strtotime($from.' -1 day'))])->save());
            $overlap = LeavePolicy::query()->where($scope)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
                ->when($to, fn ($q) => $q->where('effective_from', '<=', $to))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => __('This overlaps an existing policy for the same scope.')]);
            }
            $policy = new LeavePolicy(collect($data)->except('legal_entity_id')->all());
            $policy->forceFill(['leave_type_id' => $leaveType->id, 'legal_entity_id' => $entity?->id, 'created_by' => $request->user()->id])->save();

            return $policy->setRelation('legalEntity', $entity);
        });

        return response()->json(['data' => $this->policy($policy)], 201);
    }

    public function allocate(Request $request, LeaveType $leaveType, LeaveService $service): JsonResponse
    {
        $this->configure($request);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'as_of' => ['sometimes', 'date_format:Y-m-d']]);

        return response()->json(['data' => $service->allocate($leaveType, (int) $data['year'], $request->user(), $data['as_of'] ?? null)]);
    }

    public function carryForward(Request $request, LeaveType $leaveType, LeaveService $service): JsonResponse
    {
        $this->configure($request);
        $year = (int) $request->validate(['from_year' => ['required', 'integer', 'between:2000,2100']])['from_year'];
        abort_if($year >= (int) now()->format('Y'), 422, __('Carry-forward can only run for a completed year.'));

        return response()->json(['data' => $service->carryForward($leaveType, $year, $request->user())]);
    }

    private function validated(Request $request, RuleResolver $rules, ?LeaveType $existing = null): array
    {
        $req = $existing ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'code' => [$req, 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('leave_types')->where('tenant_id', $request->user()->tenant_id)->ignore($existing?->id)],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
            'rule_key' => ['sometimes', 'nullable', 'string', 'max:40', 'required_with:country_code'],
            'is_paid' => ['sometimes', 'boolean'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'requires_document' => ['sometimes', 'boolean'],
            'approval_steps' => ['sometimes', 'integer', 'between:1,2'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $country = $data['country_code'] ?? $existing?->country_code;
        $key = $data['rule_key'] ?? $existing?->rule_key;
        if ($country && $key) {
            $keys = $rules->effective($country, null, RuleCategory::Leave, now()->toDateString())->flatMap(fn ($v) => RuleCategory::Leave->entryKeys($v->payload))->all();
            if (! in_array($key, $keys, true)) {
                throw ValidationException::withMessages(['rule_key' => __('This leave type is not in the approved leave rules for :country.', ['country' => $country])]);
            }
        }

        return $data;
    }

    private function configure(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::LeaveConfigure), 403);
    }

    private function present(LeaveType $t): array
    {
        return ['id' => $t->public_id, 'name' => $t->name, 'code' => $t->code, 'country_code' => $t->country_code, 'rule_key' => $t->rule_key,
            'is_paid' => $t->is_paid, 'allow_half_day' => $t->allow_half_day, 'requires_document' => $t->requires_document,
            'approval_steps' => $t->approval_steps, 'status' => $t->status];
    }

    private function policy(LeavePolicy $p): array
    {
        return ['id' => $p->public_id, 'legal_entity' => $p->legalEntity ? ['id' => $p->legalEntity->public_id, 'name' => $p->legalEntity->name] : null,
            'effective_from' => $p->effective_from->toDateString(), 'effective_to' => $p->effective_to?->toDateString(),
            'annual_entitlement' => $p->annual_entitlement, 'accrual' => $p->accrual, 'prorate_new_joiners' => $p->prorate_new_joiners,
            'carry_forward_max' => $p->carry_forward_max, 'carry_forward_expiry_months' => $p->carry_forward_expiry_months,
            'allow_negative' => $p->allow_negative, 'max_consecutive_days' => $p->max_consecutive_days, 'min_notice_days' => $p->min_notice_days];
    }
}
