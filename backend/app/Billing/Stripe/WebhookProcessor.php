<?php

namespace App\Billing\Stripe;

use App\Audit\AuditEvents;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Verifies Stripe webhook signatures and applies each event exactly once. The event id is inserted under a unique
 * key in the same transaction as its effects: a repeat delivery is acknowledged without being applied again, and a
 * failure rolls everything back so Stripe retries. Events older than the last one applied to a record are ignored.
 */
final class WebhookProcessor
{
    /** @return array<string, mixed> the verified event */
    public function verify(string $payload, string $header): array
    {
        $secret = (string) config('billing.stripe.webhook_secret');
        if ($secret === '') {
            throw new InvalidSignature('not_configured');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new InvalidSignature('malformed');
        }
        if (abs(now()->getTimestamp() - $timestamp) > (int) config('billing.stripe.tolerance')) {
            throw new InvalidSignature('expired');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        if (array_filter($signatures, fn ($s) => hash_equals($expected, $s)) === []) {
            throw new InvalidSignature('mismatch');
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
            throw new InvalidSignature('malformed');
        }

        return $event;
    }

    /** @return array{duplicate: bool, outcome: string} */
    public function process(array $event): array
    {
        if (BillingEvent::query()->where(['provider' => 'stripe', 'event_id' => $event['id']])->exists()) {
            return ['duplicate' => true, 'outcome' => 'duplicate'];
        }
        try {
            return DB::transaction(function () use ($event) {
                $record = new BillingEvent;
                $record->forceFill(['provider' => 'stripe', 'event_id' => $event['id'], 'type' => mb_substr($event['type'], 0, 100), 'outcome' => 'processing', 'created_at' => now()])->save();
                [$outcome, $note, $tenantId] = $this->apply($event['type'], $event['data']['object'], (int) ($event['created'] ?? now()->getTimestamp()));
                $record->forceFill(['outcome' => $outcome, 'note' => $note, 'tenant_id' => $tenantId])->save();

                return ['duplicate' => false, 'outcome' => $outcome];
            });
        } catch (UniqueConstraintViolationException) {
            return ['duplicate' => true, 'outcome' => 'duplicate'];
        }
    }

    /** @return array{0: string, 1: ?string, 2: ?int} */
    private function apply(string $type, array $object, int $created): array
    {
        return match (true) {
            $type === 'checkout.session.completed' => $this->checkoutCompleted($object),
            str_starts_with($type, 'customer.subscription.') => $this->syncSubscription($object, $created, $type === 'customer.subscription.deleted'),
            str_starts_with($type, 'invoice.') => $this->syncInvoice($object, $created, $type),
            default => ['ignored', 'unhandled event type', null],
        };
    }

    private function checkoutCompleted(array $session): array
    {
        $tenant = $this->tenantByPublicId($session['client_reference_id'] ?? $session['metadata']['tenant'] ?? null);
        $subscription = $tenant ? Subscription::forTenant($tenant) : null;
        if (! $subscription || ($session['mode'] ?? null) !== 'subscription') {
            return ['ignored', 'no matching organization', null];
        }
        if ($subscription->stripe_customer_id && $subscription->stripe_customer_id !== ($session['customer'] ?? null)) {
            return ['ignored', 'customer mismatch', $tenant->id];
        }
        $subscription->forceFill(['stripe_customer_id' => $session['customer'] ?? null, 'stripe_subscription_id' => $session['subscription'] ?? $subscription->stripe_subscription_id])->save();

        return ['processed', null, $tenant->id];
    }

    private function syncSubscription(array $remote, int $created, bool $deleted): array
    {
        $subscription = Subscription::query()->withoutGlobalScopes()->where('stripe_subscription_id', $remote['id'] ?? '')->lockForUpdate()->first();
        if (! $subscription && ($tenant = $this->tenantByPublicId($remote['metadata']['tenant'] ?? null))) {
            $subscription = Subscription::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            // A different live Stripe subscription is never replaced by another one.
            if ($subscription && $subscription->provider === 'stripe' && $subscription->stripe_subscription_id && $subscription->status !== 'canceled') {
                return ['ignored', 'not the current subscription', $tenant->id];
            }
        }
        if (! $subscription || $subscription->provider === 'manual') {
            return ['ignored', $subscription ? 'manually managed' : 'no matching organization', $subscription?->tenant_id];
        }
        if ($subscription->stripe_customer_id && ($remote['customer'] ?? null) !== $subscription->stripe_customer_id) {
            return ['ignored', 'customer mismatch', $subscription->tenant_id];
        }
        if ($subscription->stripe_synced_at !== null && $created < $subscription->stripe_synced_at) {
            return ['ignored', 'stale event', $subscription->tenant_id];
        }
        $status = $deleted ? 'canceled' : match ($remote['status'] ?? '') {
            'trialing' => 'trialing',
            'active' => 'active',
            'past_due' => 'past_due',
            'unpaid', 'paused' => 'unpaid',
            'canceled', 'incomplete_expired' => 'canceled',
            default => null, // incomplete: the first payment is still being attempted
        };
        if ($status === null) {
            return ['ignored', 'incomplete subscription', $subscription->tenant_id];
        }
        $ts = fn ($v) => is_numeric($v) ? CarbonImmutable::createFromTimestamp((int) $v) : null;
        $item = $remote['items']['data'][0] ?? [];
        $changes = [
            'provider' => 'stripe',
            'status' => $status,
            'stripe_subscription_id' => $remote['id'],
            'stripe_customer_id' => $remote['customer'] ?? $subscription->stripe_customer_id,
            'cancel_at_period_end' => (bool) ($remote['cancel_at_period_end'] ?? false),
            'current_period_end' => $ts($remote['current_period_end'] ?? $item['current_period_end'] ?? null),
            'canceled_at' => $status === 'canceled' ? ($ts($remote['ended_at'] ?? $remote['canceled_at'] ?? null) ?? now()) : null,
            'trial_ends_at' => $status === 'trialing' ? $ts($remote['trial_end'] ?? null) : $subscription->trial_ends_at,
            'past_due_since' => in_array($status, ['past_due', 'unpaid'], true) ? ($subscription->past_due_since ?? now()) : null,
            'stripe_synced_at' => $created,
        ];
        $priceId = $item['price']['id'] ?? null;
        $match = is_string($priceId) ? Plan::forStripePrice($priceId) : null;
        if ($match) {
            [$plan, $currency, $interval] = $match;
            $scheduled = $subscription->pending_plan_id === $plan->id && $subscription->plan_change_at?->isFuture();
            $changes += ['stripe_price_id' => $priceId, 'currency' => $currency];
            if (! $scheduled) {
                $changes += ['plan_id' => $plan->id, 'billing_interval' => $interval, 'pending_plan_id' => null, 'pending_interval' => null, 'plan_change_at' => null];
            }
        }
        $subscription->forceFill($changes + ['version' => $subscription->version + 1])->save();

        return ['processed', $match ? null : 'unknown price', $subscription->tenant_id];
    }

    private function syncInvoice(array $remote, int $created, string $type): array
    {
        $subscription = Subscription::query()->withoutGlobalScopes()->where('stripe_customer_id', $remote['customer'] ?? '')->first();
        if (! $subscription || ! is_string($remote['id'] ?? null)) {
            return ['ignored', 'no matching organization', null];
        }
        $invoice = Invoice::query()->withoutGlobalScopes()->where('stripe_invoice_id', $remote['id'])->lockForUpdate()->first() ?? new Invoice;
        if ($invoice->exists && (int) $invoice->tenant_id !== (int) $subscription->tenant_id) {
            return ['ignored', 'customer mismatch', $subscription->tenant_id];
        }
        if ($invoice->exists && $invoice->stripe_synced_at !== null && $created < $invoice->stripe_synced_at) {
            return ['ignored', 'stale event', $subscription->tenant_id];
        }
        $ts = fn ($v) => is_numeric($v) ? CarbonImmutable::createFromTimestamp((int) $v) : null;
        $url = fn ($v) => is_string($v) && str_starts_with($v, 'https://') ? mb_substr($v, 0, 1000) : null;
        $invoice->forceFill([
            'tenant_id' => $subscription->tenant_id,
            'stripe_invoice_id' => $remote['id'],
            'number' => isset($remote['number']) ? mb_substr((string) $remote['number'], 0, 64) : null,
            'status' => in_array($remote['status'] ?? null, ['draft', 'open', 'paid', 'void', 'uncollectible'], true) ? $remote['status'] : 'open',
            'currency' => strtoupper(substr((string) ($remote['currency'] ?? 'usd'), 0, 3)),
            'amount_due' => (int) ($remote['amount_due'] ?? 0),
            'amount_paid' => (int) ($remote['amount_paid'] ?? 0),
            'period_start' => $ts($remote['period_start'] ?? null),
            'period_end' => $ts($remote['period_end'] ?? null),
            'issued_at' => $ts($remote['status_transitions']['finalized_at'] ?? null) ?? $ts($remote['created'] ?? null),
            'paid_at' => $ts($remote['status_transitions']['paid_at'] ?? null),
            'hosted_invoice_url' => $url($remote['hosted_invoice_url'] ?? null),
            'invoice_pdf_url' => $url($remote['invoice_pdf'] ?? null),
            'stripe_synced_at' => $created,
        ])->save();

        if ($type === 'invoice.payment_failed') {
            if ($subscription->past_due_since === null) {
                $subscription->forceFill(['past_due_since' => now()])->saveQuietly();
            }
            AuditLog::record(AuditEvents::BILLING_PAYMENT_FAILED, $invoice, metadata: ['invoice' => $invoice->number], outcome: 'failure', tenantId: $subscription->tenant_id);
        } elseif ($type === 'invoice.paid') {
            AuditLog::record(AuditEvents::BILLING_PAYMENT_SUCCEEDED, $invoice, metadata: ['invoice' => $invoice->number], tenantId: $subscription->tenant_id);
        }

        return ['processed', null, $subscription->tenant_id];
    }

    private function tenantByPublicId(mixed $publicId): ?Tenant
    {
        return is_string($publicId) && $publicId !== '' ? Tenant::query()->where('public_id', $publicId)->first() : null;
    }
}
