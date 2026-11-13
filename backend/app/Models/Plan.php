<?php

namespace App\Models;

use App\Billing\Feature;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** A subscription plan. Platform-level (no tenant); managed by platform staff. */
class Plan extends Model
{
    use Auditable, HasPublicId;

    public const INTERVALS = ['monthly', 'annual'];

    protected $fillable = ['name', 'description', 'employee_limit', 'ai_requests_monthly', 'features', 'trial_days', 'prices', 'rank', 'active'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['features' => 'array', 'prices' => 'array', 'active' => 'boolean', 'employee_limit' => 'integer',
            'ai_requests_monthly' => 'integer', 'trial_days' => 'integer', 'rank' => 'integer'];
    }

    public function auditEvent(string $action): string
    {
        return 'platform.plan_'.$action;
    }

    public function includes(Feature $feature): bool
    {
        return in_array($feature->value, $this->features ?? [], true);
    }

    public function stripePrice(string $currency, string $interval): ?string
    {
        return $this->prices[$currency][$interval]['stripe_price'] ?? null;
    }

    public function amount(string $currency, string $interval): ?int
    {
        $amount = $this->prices[$currency][$interval]['amount'] ?? null;

        return $amount === null ? null : (int) $amount;
    }

    /** @return array{0: self, 1: string, 2: string}|null plan, currency and interval for a Stripe price id */
    public static function forStripePrice(string $priceId): ?array
    {
        foreach (self::query()->get() as $plan) {
            foreach ($plan->prices ?? [] as $currency => $intervals) {
                foreach ((array) $intervals as $interval => $price) {
                    if (($price['stripe_price'] ?? null) === $priceId) {
                        return [$plan, (string) $currency, (string) $interval];
                    }
                }
            }
        }

        return null;
    }
}
