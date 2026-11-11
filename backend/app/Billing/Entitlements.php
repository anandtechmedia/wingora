<?php

namespace App\Billing;

use App\Models\Employee;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Server-side enforcement of plan limits and the usage figures shown to customers and platform staff. */
final class Entitlements
{
    public const AI_REQUESTS = 'ai_requests';

    public function subscription(Tenant $tenant): Subscription
    {
        return Subscription::forTenant($tenant)
            ?? throw new BillingRestricted('no_subscription', __('This organization has no subscription.'));
    }

    public function assertFeature(Tenant $tenant, Feature $feature): void
    {
        if (! $this->subscription($tenant)->effectivePlan()->includes($feature)) {
            throw new BillingRestricted('feature_not_in_plan', __('Your plan does not include this module.'), ['feature' => $feature->value]);
        }
    }

    /** Call inside the transaction that creates or reactivates employees; the subscription row lock serializes concurrent adds. */
    public function assertEmployeeCapacity(Tenant $tenant, int $adding = 1): void
    {
        $subscription = Subscription::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->first()
            ?? throw new BillingRestricted('no_subscription', __('This organization has no subscription.'));
        $limit = $subscription->effectivePlan()->employee_limit;
        if ($limit !== null && $this->employeeCount($tenant) + $adding > $limit) {
            throw new BillingRestricted('employee_limit_reached', __('Your plan allows up to :limit employees. Upgrade to add more.', ['limit' => $limit]), ['limit' => $limit]);
        }
    }

    /**
     * Runs one AI request against the monthly allowance. The slot is reserved under a row lock before the call,
     * so concurrent requests cannot exceed the limit, and released if the call fails.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public function meterAi(Tenant $tenant, callable $call): mixed
    {
        $limit = $this->subscription($tenant)->effectivePlan()->ai_requests_monthly;
        if ($limit <= 0) {
            throw new BillingRestricted('feature_not_in_plan', __('Your plan does not include AI assistance.'), ['feature' => 'ai']);
        }
        $period = now()->utc()->format('Y-m');
        DB::transaction(function () use ($tenant, $period, $limit) {
            DB::table('usage_counters')->insertOrIgnore(['tenant_id' => $tenant->id, 'metric' => self::AI_REQUESTS, 'period' => $period,
                'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('usage_counters')->where(['tenant_id' => $tenant->id, 'metric' => self::AI_REQUESTS, 'period' => $period])->lockForUpdate()->first();
            if ((int) $row->quantity >= $limit) {
                throw new BillingRestricted('ai_limit_reached', __('Your organization has used its :limit AI requests for this month.', ['limit' => $limit]), ['limit' => $limit]);
            }
            DB::table('usage_counters')->where('id', $row->id)->update(['quantity' => DB::raw('quantity + 1'), 'updated_at' => now()]);
        });
        try {
            return $call();
        } catch (Throwable $e) {
            DB::table('usage_counters')->where(['tenant_id' => $tenant->id, 'metric' => self::AI_REQUESTS, 'period' => $period])
                ->where('quantity', '>', 0)->update(['quantity' => DB::raw('quantity - 1')]);
            throw $e;
        }
    }

    public function employeeCount(Tenant $tenant): int
    {
        return Employee::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', '!=', 'terminated')->count();
    }

    /** @return array{employees: array{used: int, limit: ?int}, ai_requests: array{used: int, limit: int, period: string}, storage_bytes: int} */
    public function usage(Tenant $tenant, ?Subscription $subscription = null): array
    {
        $plan = ($subscription ?? $this->subscription($tenant))->effectivePlan();
        $period = now()->utc()->format('Y-m');

        return [
            'employees' => ['used' => $this->employeeCount($tenant), 'limit' => $plan->employee_limit],
            'ai_requests' => ['used' => (int) DB::table('usage_counters')->where(['tenant_id' => $tenant->id, 'metric' => self::AI_REQUESTS, 'period' => $period])->value('quantity'),
                'limit' => $plan->ai_requests_monthly, 'period' => $period],
            'storage_bytes' => (int) StoredFile::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sum('size'),
        ];
    }
}
