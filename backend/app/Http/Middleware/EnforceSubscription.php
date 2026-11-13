<?php

namespace App\Http\Middleware;

use App\Billing\BillingRestricted;
use App\Billing\Feature;
use App\Models\Subscription;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the documented billing rules to every organization request: modules outside the plan are refused, and a
 * read-only organization (trial over, payment overdue, cancelled) may read but not change data. Billing and the
 * account (export, deletion, support access) stay usable so the owner can always pay, export or leave.
 */
class EnforceSubscription
{
    private const ALWAYS_ALLOWED = ['billing', 'account'];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenancy::current();
        $path = preg_replace('#^api/v1/#', '', $request->path());
        if (! $tenant || in_array(explode('/', $path)[0], self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }
        $subscription = Subscription::forTenant($tenant)
            ?? throw new BillingRestricted('no_subscription', __('This organization has no subscription.'));

        $feature = Feature::forPath($path);
        if ($feature && ! $subscription->effectivePlan()->includes($feature)) {
            throw new BillingRestricted('feature_not_in_plan', __('Your plan does not include this module.'), ['feature' => $feature->value]);
        }
        $access = $subscription->access();
        if ($access['mode'] === 'read_only' && ! $request->isMethodSafe()) {
            throw new BillingRestricted('subscription_inactive', __('Your organization is read-only until billing is resolved.'), ['access_reason' => $access['reason']]);
        }

        return $next($request);
    }
}
