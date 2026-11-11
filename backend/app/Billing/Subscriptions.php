<?php

namespace App\Billing;

use App\Audit\AuditEvents;
use App\Billing\Stripe\StripeClient;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Subscription lifecycle: trial, Stripe checkout, upgrades, scheduled downgrades, cancellation and resumption. */
final class Subscriptions
{
    public function __construct(private readonly StripeClient $stripe, private readonly Entitlements $entitlements) {}

    public static function currencyFor(Tenant $tenant): string
    {
        return config('billing.currencies')[$tenant->country_code] ?? 'USD';
    }

    public function startTrial(Tenant $tenant): Subscription
    {
        $plan = Plan::query()->where('code', config('billing.trial_plan'))->where('active', true)->first()
            ?? Plan::query()->where('active', true)->orderBy('rank')->firstOrFail();
        $subscription = new Subscription;
        $subscription->forceFill(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'trialing', 'provider' => 'trial',
            'currency' => self::currencyFor($tenant), 'trial_ends_at' => now()->addDays($plan->trial_days)])->save();

        return $subscription;
    }

    /** Hosted Stripe Checkout for an organization that has no live Stripe subscription yet. */
    public function checkout(Subscription $subscription, Plan $plan, string $interval, User $actor): string
    {
        $this->guardManual($subscription);
        abort_if($subscription->provider === 'stripe' && $subscription->status !== 'canceled', 409, __('This organization already has a subscription. Change the plan instead.'));
        $tenant = $subscription->tenant;
        $currency = self::currencyFor($tenant);
        $price = $this->price($plan, $currency, $interval);
        $this->guardCapacity($tenant, $plan);

        if (! $subscription->stripe_customer_id) {
            $customer = $this->stripe->post('customers', ['name' => $tenant->name, 'email' => $actor->email, 'metadata' => ['tenant' => $tenant->public_id]]);
            $subscription->forceFill(['stripe_customer_id' => $customer['id']])->save();
        }
        $front = rtrim((string) config('security.frontend_url'), '/');
        $params = [
            'mode' => 'subscription',
            'customer' => $subscription->stripe_customer_id,
            'client_reference_id' => $tenant->public_id,
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'success_url' => $front.'/billing?checkout=success',
            'cancel_url' => $front.'/billing?checkout=cancelled',
            'metadata' => ['tenant' => $tenant->public_id, 'plan' => $plan->code, 'interval' => $interval],
            'subscription_data' => ['metadata' => ['tenant' => $tenant->public_id]],
        ];
        // Keep the remaining free trial: Stripe requires a trial end at least 48 hours away.
        if ($subscription->status === 'trialing' && $subscription->trial_ends_at?->gt(now()->addHours(48))) {
            $params['subscription_data']['trial_end'] = $subscription->trial_ends_at->getTimestamp();
        }
        $session = $this->stripe->post('checkout/sessions', $params);
        AuditLog::record(AuditEvents::BILLING_CHECKOUT_STARTED, $subscription, metadata: ['plan' => $plan->code, 'interval' => $interval, 'currency' => $currency]);

        return (string) $session['url'];
    }

    /**
     * Upgrades (higher plan, or monthly to annual on the same plan) apply now and Stripe invoices the prorated
     * difference. Downgrades switch the Stripe price without proration and take effect at the period end.
     */
    public function change(Subscription $subscription, Plan $plan, string $interval, int $version): Subscription
    {
        $this->guardManual($subscription);
        abort_unless($subscription->provider === 'stripe' && $subscription->stripe_subscription_id && in_array($subscription->status, ['active', 'trialing', 'past_due'], true),
            409, __('Choose a plan at checkout first.'));
        abort_if($subscription->version !== $version, 409, __('The subscription changed. Reload and try again.'));
        $current = $subscription->effectivePlan();
        $currentInterval = $subscription->billing_interval ?? 'monthly';
        if ($current->id === $plan->id && $currentInterval === $interval && ! $subscription->pending_plan_id) {
            throw ValidationException::withMessages(['plan' => __('This is already your plan.')]);
        }
        $price = $this->price($plan, $subscription->currency ?? self::currencyFor($subscription->tenant), $interval);
        $upgrade = $plan->rank > $subscription->plan->rank || ($plan->rank === $subscription->plan->rank && $interval === 'annual' && $currentInterval === 'monthly');
        if (! $upgrade) {
            $this->guardCapacity($subscription->tenant, $plan);
        }

        // Claim the version before calling Stripe, so two concurrent changes cannot both reach the provider.
        $claimed = Subscription::query()->withoutGlobalScopes()->whereKey($subscription->id)->where('version', $version)->increment('version');
        abort_if($claimed === 0, 409, __('The subscription changed. Reload and try again.'));

        $path = 'subscriptions/'.$subscription->stripe_subscription_id;
        $remote = $this->stripe->get($path);
        $item = $remote['items']['data'][0]['id'] ?? throw new ConflictHttpException(__('The payment provider returned an unexpected subscription.'));
        $params = ['items' => [['id' => $item, 'price' => $price]], 'proration_behavior' => $upgrade ? 'always_invoice' : 'none'];
        if ($upgrade) {
            // The upgrade only takes effect in Stripe once its prorated invoice is paid.
            $params['payment_behavior'] = 'pending_if_incomplete';
        } else {
            $params['cancel_at_period_end'] = 'false';
        }
        $updated = $this->stripe->post($path, $params);
        if ($upgrade && ! empty($updated['pending_update'])) {
            AuditLog::record(AuditEvents::BILLING_PAYMENT_FAILED, $subscription, metadata: ['plan' => $plan->code, 'interval' => $interval, 'reason' => 'upgrade_payment_incomplete'], outcome: 'failure');
            throw new BillingRestricted('upgrade_payment_failed', __('The payment for the upgrade did not go through, so your plan has not changed. Update your payment method and try again.'));
        }
        // Pending updates cannot carry other changes, so a scheduled cancellation is only lifted once the upgrade is paid.
        if ($upgrade && $subscription->cancel_at_period_end) {
            $this->stripe->post($path, ['cancel_at_period_end' => 'false']);
        }

        return DB::transaction(function () use ($subscription, $plan, $interval, $price, $upgrade) {
            $locked = Subscription::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($subscription->id);
            $old = ['plan' => $locked->plan->code, 'interval' => $locked->billing_interval];
            $locked->forceFill($upgrade || $plan->id === $locked->plan_id && $interval === $locked->billing_interval
                ? ['plan_id' => $plan->id, 'billing_interval' => $interval, 'stripe_price_id' => $price, 'pending_plan_id' => null, 'pending_interval' => null, 'plan_change_at' => null]
                : ['pending_plan_id' => $plan->id, 'pending_interval' => $interval, 'stripe_price_id' => $price, 'plan_change_at' => $locked->current_period_end ?? now()]);
            $locked->forceFill(['cancel_at_period_end' => false, 'version' => $locked->version + 1])->saveQuietly();
            AuditLog::record($upgrade ? AuditEvents::BILLING_PLAN_CHANGED : AuditEvents::BILLING_PLAN_CHANGE_SCHEDULED, $locked, $old,
                ['plan' => $plan->code, 'interval' => $interval, 'effective' => $upgrade ? 'now' : $locked->plan_change_at?->toIso8601String()]);

            return $locked->fresh(['plan', 'pendingPlan']);
        });
    }

    /** Stops renewal at the end of the paid period (Stripe), or ends a free trial now. Data is never deleted here. */
    public function cancel(Subscription $subscription): Subscription
    {
        $this->guardManual($subscription);
        abort_if($subscription->status === 'canceled' || $subscription->cancel_at_period_end, 409, __('The subscription is already cancelled.'));
        if ($subscription->provider === 'stripe') {
            $this->stripe->post('subscriptions/'.$subscription->stripe_subscription_id, ['cancel_at_period_end' => 'true']);
            $subscription->forceFill(['cancel_at_period_end' => true]);
        } else {
            $subscription->forceFill(['status' => 'canceled', 'canceled_at' => now()]);
        }
        $subscription->forceFill(['version' => $subscription->version + 1])->saveQuietly();
        AuditLog::record(AuditEvents::BILLING_SUBSCRIPTION_CANCELED, $subscription, metadata: ['at_period_end' => $subscription->provider === 'stripe']);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function resume(Subscription $subscription): Subscription
    {
        $this->guardManual($subscription);
        if ($subscription->provider === 'stripe' && $subscription->cancel_at_period_end && $subscription->status !== 'canceled') {
            $this->stripe->post('subscriptions/'.$subscription->stripe_subscription_id, ['cancel_at_period_end' => 'false']);
            $subscription->forceFill(['cancel_at_period_end' => false]);
        } elseif ($subscription->provider === 'trial' && $subscription->status === 'canceled' && $subscription->trial_ends_at?->isFuture()) {
            $subscription->forceFill(['status' => 'trialing', 'canceled_at' => null]);
        } else {
            abort(409, __('This subscription cannot be resumed. Choose a plan instead.'));
        }
        $subscription->forceFill(['version' => $subscription->version + 1])->saveQuietly();
        AuditLog::record(AuditEvents::BILLING_SUBSCRIPTION_RESUMED, $subscription);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function portal(Subscription $subscription): string
    {
        abort_unless($subscription->stripe_customer_id, 409, __('There is no payment account yet. Choose a plan first.'));
        $session = $this->stripe->post('billing_portal/sessions', ['customer' => $subscription->stripe_customer_id,
            'return_url' => rtrim((string) config('security.frontend_url'), '/').'/billing']);

        return (string) $session['url'];
    }

    private function price(Plan $plan, string $currency, string $interval): string
    {
        abort_unless($plan->active, 422, __('This plan is not available.'));

        return $plan->stripePrice($currency, $interval)
            ?? throw ValidationException::withMessages(['plan' => __('This plan is not available for :interval billing in :currency.', ['interval' => $interval, 'currency' => $currency])]);
    }

    private function guardCapacity(Tenant $tenant, Plan $plan): void
    {
        if ($plan->employee_limit !== null && ($count = $this->entitlements->employeeCount($tenant)) > $plan->employee_limit) {
            throw ValidationException::withMessages(['plan' => __('You have :count employees; this plan allows :limit.', ['count' => $count, 'limit' => $plan->employee_limit])]);
        }
    }

    private function guardManual(Subscription $subscription): void
    {
        abort_if($subscription->provider === 'manual', 409, __('Your plan is managed by Wingora. Contact support to change it.'));
    }
}
