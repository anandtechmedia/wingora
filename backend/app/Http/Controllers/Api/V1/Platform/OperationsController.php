<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Billing\Entitlements;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Platform\Health;
use App\Security\Permission;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Subscription overview, usage monitoring and operational health for platform staff. No HR data is read here. */
class OperationsController extends Controller
{
    public function subscriptions(Request $request): JsonResponse
    {
        $this->can($request, Permission::PlatformBillingView);
        $base = fn () => Subscription::query()->withoutGlobalScopes()->whereHas('tenant', fn ($q) => $q->where('status', '!=', 'deleted'));
        $mrr = [];
        $base()->with('plan')->where('provider', 'stripe')->whereIn('status', ['active', 'past_due'])->lazyById(500)->each(function (Subscription $s) use (&$mrr) {
            $interval = $s->billing_interval ?? 'monthly';
            $amount = $s->currency ? $s->plan->amount($s->currency, $interval) : null;
            if ($amount !== null) {
                $mrr[$s->currency] = ($mrr[$s->currency] ?? 0) + intdiv($interval === 'annual' ? $amount : $amount * 12, 12);
            }
        });
        ksort($mrr);

        return response()->json(['data' => [
            'by_status' => $base()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'by_provider' => $base()->toBase()->selectRaw('provider, count(*) as total')->groupBy('provider')->pluck('total', 'provider'),
            'by_plan' => $base()->toBase()->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->selectRaw('plans.code as code, count(*) as total')->groupBy('plans.code')->pluck('total', 'code'),
            'mrr_minor' => (object) $mrr,
            'trials_ending_7d' => $base()->where('status', 'trialing')->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count(),
            'cancel_scheduled' => $base()->where('cancel_at_period_end', true)->count(),
            'tenants' => ['active' => Tenant::query()->where('status', 'active')->count(), 'suspended' => Tenant::query()->where('status', 'suspended')->count()],
        ]]);
    }

    public function usage(Request $request): JsonResponse
    {
        $this->can($request, Permission::PlatformBillingView);
        $data = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['name', 'employees', 'ai_requests', 'storage_bytes'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $period = now()->utc()->format('Y-m');
        $count = fn (string $table, callable $where) => fn (Builder $q) => $where($q->from($table)->whereColumn("{$table}.tenant_id", 'tenants.id'));
        $query = Tenant::query()->where('tenants.status', '!=', 'deleted')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->select(['tenants.*', 'plans.code as plan_code', 'plans.employee_limit', 'plans.ai_requests_monthly', 'subscriptions.status as subscription_status'])
            ->selectSub($count('employees', fn ($q) => $q->selectRaw('count(*)')->where('employees.status', '!=', 'terminated')), 'employees_used')
            ->selectSub($count('usage_counters', fn ($q) => $q->select('quantity')->where('metric', Entitlements::AI_REQUESTS)->where('period', $period)->limit(1)), 'ai_used')
            ->selectSub($count('stored_files', fn ($q) => $q->selectRaw('coalesce(sum(size), 0)')->whereNull('stored_files.deleted_at')), 'storage_used')
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('tenants.name', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('tenants.slug', 'like', '%'.addcslashes($s, '%_\\').'%')));
        match ($data['sort'] ?? 'name') {
            'employees' => $query->orderByDesc('employees_used'),
            'ai_requests' => $query->orderByDesc('ai_used'),
            'storage_bytes' => $query->orderByDesc('storage_used'),
            default => $query->orderBy('tenants.name'),
        };

        return response()->json($query->orderBy('tenants.id')->paginate((int) ($data['per_page'] ?? 25))->through(fn (Tenant $t) => [
            'id' => $t->public_id, 'name' => $t->name, 'status' => $t->status, 'plan' => $t->plan_code, 'subscription_status' => $t->subscription_status,
            'employees' => ['used' => (int) $t->employees_used, 'limit' => $t->employee_limit === null ? null : (int) $t->employee_limit],
            'ai_requests' => ['used' => (int) $t->ai_used, 'limit' => $t->ai_requests_monthly === null ? null : (int) $t->ai_requests_monthly, 'period' => $period],
            'storage_bytes' => (int) $t->storage_used,
        ]));
    }

    public function health(Request $request, Health $health): JsonResponse
    {
        $this->can($request, Permission::PlatformHealthView);

        return response()->json(['data' => $health->report()]);
    }

    private function can(Request $request, Permission $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }
}
