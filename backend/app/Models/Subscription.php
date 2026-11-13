<?php

namespace App\Models;

use App\Models\Concerns\BillingAudited;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One per organization. `access()` applies the billing rules in docs/architecture/billing.md: full access while
 * trialing, paid, or within the grace period after a failed payment; read-only otherwise. Suspension is separate
 * (tenants.status) and blocks sign-in entirely.
 */
class Subscription extends TenantModel
{
    use BillingAudited;

    public const STATUSES = ['trialing', 'active', 'past_due', 'unpaid', 'canceled'];

    public const PROVIDERS = ['trial', 'manual', 'stripe'];

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'plan_id', 'pending_plan_id', 'stripe_customer_id', 'stripe_subscription_id', 'stripe_price_id', 'stripe_synced_at'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'current_period_end' => 'datetime', 'canceled_at' => 'datetime', 'past_due_since' => 'datetime',
            'plan_change_at' => 'datetime', 'cancel_at_period_end' => 'boolean', 'version' => 'integer', 'stripe_synced_at' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public static function forTenant(Tenant|int $tenant): ?self
    {
        return self::query()->withoutGlobalScopes()->with(['plan', 'pendingPlan'])
            ->where('tenant_id', $tenant instanceof Tenant ? $tenant->id : $tenant)->first();
    }

    /** The plan whose entitlements apply now: a scheduled downgrade takes over once its date has passed. */
    public function effectivePlan(): Plan
    {
        return $this->pending_plan_id && $this->plan_change_at && ! $this->plan_change_at->isFuture() && $this->pendingPlan
            ? $this->pendingPlan
            : $this->plan;
    }

    public function graceEndsAt(): ?CarbonInterface
    {
        return $this->past_due_since?->copy()->addDays((int) config('billing.grace_days'));
    }

    /** @return array{mode: 'full'|'read_only', reason: ?string} */
    public function access(): array
    {
        $readOnly = fn (string $reason) => ['mode' => 'read_only', 'reason' => $reason];

        return match ($this->status) {
            'trialing' => $this->trial_ends_at === null || $this->trial_ends_at->isFuture() ? ['mode' => 'full', 'reason' => null] : $readOnly('trial_expired'),
            'active' => $this->provider === 'manual' && $this->current_period_end !== null && $this->current_period_end->isPast()
                ? $readOnly('term_ended') : ['mode' => 'full', 'reason' => null],
            'past_due' => $this->graceEndsAt()?->isFuture() ?? true ? ['mode' => 'full', 'reason' => 'payment_failed'] : $readOnly('payment_overdue'),
            'unpaid' => $readOnly('payment_overdue'),
            default => $readOnly('canceled'),
        };
    }
}
