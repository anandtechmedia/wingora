<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Billing\Feature;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Platform staff manage the plan catalog. Prices here are for display; Stripe prices are what customers are charged. */
class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PlatformBillingView), 403);
        $counts = Subscription::query()->withoutGlobalScopes()->selectRaw('plan_id, count(*) as c')->groupBy('plan_id')->pluck('c', 'plan_id');

        return response()->json(['data' => Plan::query()->orderBy('rank')->get()->map(fn (Plan $p) => $this->present($p) + ['subscriptions' => (int) ($counts[$p->id] ?? 0)])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PlatformBillingManage), 403);
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'unique:plans,code']] + $this->rules(true));
        $plan = new Plan;
        $plan->forceFill(['code' => $data['code']])->fill($this->attributes($data) + ['prices' => [], 'features' => []]);
        $plan->save();

        return response()->json(['data' => $this->present($plan)], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PlatformBillingManage), 403);
        $plan->fill($this->attributes($request->validate($this->rules(false))))->save();

        return response()->json(['data' => $this->present($plan->fresh())]);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'employee_limit' => ['sometimes', 'nullable', 'integer', 'between:1,1000000'],
            'ai_requests_monthly' => ['sometimes', 'integer', 'between:0,10000000'],
            'features' => [$req, 'array'],
            'features.*' => ['string', 'distinct', Rule::in(Feature::values())],
            'trial_days' => ['sometimes', 'integer', 'between:0,90'],
            'rank' => ['sometimes', 'integer', 'between:0,1000'],
            'active' => ['sometimes', 'boolean'],
            'prices' => ['sometimes', 'array:'.implode(',', array_values(config('billing.currencies')))],
            'prices.*' => ['array:'.implode(',', Plan::INTERVALS)],
            'prices.*.*' => ['array:amount,stripe_price'],
            'prices.*.*.amount' => ['nullable', 'integer', 'between:0,100000000'],
            'prices.*.*.stripe_price' => ['nullable', 'string', 'max:64', 'regex:/^price_[A-Za-z0-9]+$/'],
        ];
    }

    private function attributes(array $data): array
    {
        if (isset($data['features'])) {
            $data['features'] = array_values($data['features']);
        }

        return array_intersect_key($data, array_flip(['name', 'description', 'employee_limit', 'ai_requests_monthly', 'features', 'trial_days', 'prices', 'rank', 'active']));
    }

    private function present(Plan $p): array
    {
        return ['id' => $p->public_id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'employee_limit' => $p->employee_limit,
            'ai_requests_monthly' => $p->ai_requests_monthly, 'features' => $p->features, 'trial_days' => $p->trial_days, 'prices' => (object) ($p->prices ?? []),
            'rank' => $p->rank, 'active' => $p->active];
    }
}
