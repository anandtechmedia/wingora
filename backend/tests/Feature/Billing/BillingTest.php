<?php

namespace Tests\Feature\Billing;

use App\Ai\AiUnavailable;
use App\Ai\Completion;
use App\Ai\LanguageModel;
use App\Billing\BillingRestricted;
use App\Billing\Entitlements;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Security\SecurityTestCase;

class BillingTest extends SecurityTestCase
{
    private const WHSEC = 'whsec_test_secret';

    private Tenant $tenant;

    private User $owner;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.stripe.secret' => 'sk_test_x', 'billing.stripe.webhook_secret' => self::WHSEC]);
        $this->tenant = $this->makeTenant();
        $this->owner = $this->makeUser($this->tenant, 'organization-owner');
        $this->hr = $this->makeUser($this->tenant, 'hr-administrator');
        Plan::query()->each(fn (Plan $p) => $p->forceFill(['prices' => ['USD' => [
            'monthly' => ['amount' => 1000 * $p->rank, 'stripe_price' => "price_{$p->code}m"],
            'annual' => ['amount' => 10000 * $p->rank, 'stripe_price' => "price_{$p->code}a"],
        ]]])->saveQuietly());
    }

    private function sub(): Subscription
    {
        return Subscription::forTenant($this->tenant);
    }

    private function setSub(array $attrs, ?string $plan = null): void
    {
        $this->sub()->forceFill($attrs + ($plan ? ['plan_id' => Plan::query()->where('code', $plan)->value('id')] : []))->saveQuietly();
    }

    private function webhook(array $event, ?string $secret = null, ?int $at = null)
    {
        $payload = json_encode($event);
        $t = $at ?? now()->getTimestamp();
        $sig = hash_hmac('sha256', $t.'.'.$payload, $secret ?? self::WHSEC);
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $payload);
    }

    private function subEvent(string $id, string $status, string $price, int $created, array $extra = [], string $type = 'customer.subscription.updated'): array
    {
        return ['id' => $id, 'type' => $type, 'created' => $created, 'data' => ['object' => $extra + [
            'id' => 'sub_1', 'customer' => 'cus_1', 'status' => $status, 'cancel_at_period_end' => false, 'metadata' => ['tenant' => $this->tenant->public_id],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => $price], 'current_period_end' => now()->addMonth()->getTimestamp()]]],
        ]]];
    }

    public function test_new_organizations_start_a_trial_with_the_trial_plan(): void
    {
        $tenant = Tenant::factory()->create();
        $s = Subscription::forTenant($tenant);
        $this->assertSame(['trialing', 'trial', 'growth', 'USD'], [$s->status, $s->provider, $s->plan->code, $s->currency]);
        $this->assertTrue($s->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertSame('MXN', Subscription::forTenant(Tenant::factory()->create(['country_code' => 'MX']))->currency);
    }

    public function test_only_owners_see_billing_and_no_card_data_is_exposed(): void
    {
        $this->apiAs($this->hr, 'GET', '/api/v1/billing/subscription')->assertForbidden();
        $this->apiAs($this->hr, 'POST', '/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertForbidden();
        $data = $this->apiAs($this->owner, 'GET', '/api/v1/billing/subscription')->assertOk()->json('data');
        $this->assertSame('enterprise', $data['plan']['code']);
        $this->assertSame(['mode' => 'full', 'reason' => null], $data['access']);
        $this->assertArrayNotHasKey('stripe_customer_id', $data);
        $this->assertStringNotContainsString('card', json_encode($data));
        $me = $this->apiAs($this->hr, 'GET', '/api/v1/auth/me')->json('billing');
        $this->assertSame('enterprise', $me['plan']['code']);
        $plans = $this->apiAs($this->owner, 'GET', '/api/v1/billing/plans')->assertOk()->json('data');
        $this->assertSame(['starter', 'growth', 'enterprise'], array_column($plans, 'code'));
        $this->assertTrue($plans[0]['prices']['annual']['available']);
    }

    public function test_access_rules_trial_expiry_grace_period_and_cancellation(): void
    {
        $write = fn () => $this->apiAs($this->hr, 'POST', '/api/v1/departments', ['name' => 'Dept '.uniqid()]);
        $this->setSub(['status' => 'trialing', 'provider' => 'trial', 'trial_ends_at' => now()->addDay()]);
        $write()->assertCreated();

        $this->setSub(['trial_ends_at' => now()->subMinute()]);
        $write()->assertStatus(402)->assertJsonPath('code', 'subscription_inactive')->assertJsonPath('access_reason', 'trial_expired');
        $this->apiAs($this->hr, 'GET', '/api/v1/departments')->assertOk();
        // Billing stays usable so the owner can pay.
        Http::fake(['*/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/1']), '*/customers' => Http::response(['id' => 'cus_1'])]);
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk();

        $this->setSub(['status' => 'past_due', 'provider' => 'stripe', 'past_due_since' => now()->subDays(6)]);
        $write()->assertCreated();
        $this->setSub(['past_due_since' => now()->subDays(8)]);
        $write()->assertStatus(402)->assertJsonPath('access_reason', 'payment_overdue');
        $this->setSub(['status' => 'canceled']);
        $write()->assertStatus(402)->assertJsonPath('access_reason', 'canceled');
        $this->setSub(['status' => 'active', 'provider' => 'manual', 'current_period_end' => now()->subDay()]);
        $write()->assertStatus(402)->assertJsonPath('access_reason', 'term_ended');
    }

    public function test_modules_outside_the_plan_are_refused_on_the_server(): void
    {
        $this->setSub([], 'starter');
        $this->apiAs($this->owner, 'GET', '/api/v1/payroll/runs')->assertStatus(402)->assertJsonPath('code', 'feature_not_in_plan')->assertJsonPath('feature', 'payroll');
        $this->apiAs($this->owner, 'GET', '/api/v1/recruitment/openings')->assertStatus(402);
        $employee = $this->makeEmployee($this->tenant);
        $this->apiAs($this->owner, 'GET', "/api/v1/employees/{$employee->public_id}/compensation-history")->assertStatus(402);
        $this->apiAs($this->owner, 'GET', '/api/v1/helpdesk/tickets')->assertOk();
        $this->apiAs($this->owner, 'GET', '/api/v1/employees')->assertOk();
    }

    public function test_employee_limit_is_enforced_for_create_and_reactivation(): void
    {
        $plan = Plan::query()->where('code', 'starter')->first();
        $plan->forceFill(['employee_limit' => 2])->saveQuietly();
        $this->setSub([], 'starter');
        $this->makeEmployee($this->tenant);
        $gone = $this->makeEmployee($this->tenant);
        DB::table('employees')->where('id', $gone->id)->update(['status' => 'terminated', 'termination_date' => '2026-01-01']);
        $payload = fn () => ['employee_number' => 'E-'.uniqid(), 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'work_email' => uniqid().'@example.com', 'job_title' => 'Analyst', 'hire_date' => '2026-01-05'];
        $this->apiAs($this->hr, 'POST', '/api/v1/employees', $payload())->assertCreated();
        $this->apiAs($this->hr, 'POST', '/api/v1/employees', $payload())->assertStatus(402)->assertJsonPath('code', 'employee_limit_reached')->assertJsonPath('limit', 2);
        $this->apiAs($this->hr, 'PATCH', "/api/v1/employees/{$gone->public_id}", ['status' => 'active'])->assertStatus(402);
        $this->assertSame(2, app(Entitlements::class)->employeeCount($this->tenant));
    }

    public function test_ai_requests_are_metered_and_refused_past_the_monthly_limit(): void
    {
        $fake = new class implements LanguageModel
        {
            public int $calls = 0;

            public bool $fail = false;

            public function available(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'fake';
            }

            public function complete(string $system, string $prompt, int $maxTokens = 600): Completion
            {
                $this->calls++;
                if ($this->fail) {
                    throw new AiUnavailable('down');
                }

                return new Completion('ok', 1, 1);
            }
        };
        Plan::query()->where('code', 'growth')->update(['ai_requests_monthly' => 2]);
        $this->setSub([], 'growth');
        $ent = app(Entitlements::class);
        Tenancy::run($this->tenant, function () use ($ent, $fake) {
            $ent->meterAi($this->tenant, fn () => $fake->complete('', ''));
            $fake->fail = true;
            try {
                $ent->meterAi($this->tenant, fn () => $fake->complete('', ''));
            } catch (AiUnavailable) {
            }
            $fake->fail = false;
            $ent->meterAi($this->tenant, fn () => $fake->complete('', ''));
            try {
                $ent->meterAi($this->tenant, fn () => $fake->complete('', ''));
                $this->fail('limit not enforced');
            } catch (BillingRestricted $e) {
                $this->assertSame('ai_limit_reached', $e->reason);
            }
        });
        $this->assertSame(3, $fake->calls);
        $this->assertSame(['used' => 2, 'limit' => 2], array_intersect_key($ent->usage($this->tenant)['ai_requests'], ['used' => 1, 'limit' => 1]));
        $this->setSub([], 'starter');
        $this->expectException(BillingRestricted::class);
        $ent->meterAi($this->tenant, fn () => null);
    }

    public function test_checkout_uses_hosted_stripe_pages_and_keeps_the_trial(): void
    {
        $this->setSub(['status' => 'trialing', 'provider' => 'trial', 'trial_ends_at' => now()->addDays(10)], 'growth');
        Http::fake(['*/customers' => Http::response(['id' => 'cus_1']), '*/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/abc'])]);
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'annual'])->assertOk()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/abc');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['line_items'][0]['price'] === 'price_growtha'
            && $r['client_reference_id'] === $this->tenant->public_id && (int) $r['subscription_data']['trial_end'] === $this->sub()->trial_ends_at->getTimestamp()
            && $r->hasHeader('Idempotency-Key') && ! isset($r['payment_method_types']));
        $this->assertSame('cus_1', $this->sub()->stripe_customer_id);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', 'billing.checkout_started')->exists());

        $this->apiAs($this->owner, 'POST', '/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'weekly'])->assertStatus(422);
        Plan::query()->where('code', 'starter')->update(['prices' => '{}']);
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/checkout', ['plan' => 'starter', 'interval' => 'monthly'])->assertStatus(422)->assertJsonValidationErrors('plan');
    }

    public function test_webhook_signatures_are_verified_and_replays_rejected(): void
    {
        $event = $this->subEvent('evt_1', 'active', 'price_growthm', now()->getTimestamp());
        $this->webhook($event, 'whsec_wrong')->assertStatus(400);
        $this->webhook($event, null, now()->subMinutes(6)->getTimestamp())->assertStatus(400);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertStatus(400);
        $this->assertSame(0, BillingEvent::query()->count());
        $this->assertSame('manual', $this->sub()->provider);
        $this->assertTrue(DB::table('security_events')->where('type', 'billing.webhook_rejected')->count() >= 3);
    }

    public function test_subscription_webhooks_apply_once_in_order(): void
    {
        $this->setSub(['status' => 'trialing', 'provider' => 'trial', 'trial_ends_at' => now()->addDays(3), 'stripe_customer_id' => 'cus_1'], 'growth');
        $t = now()->getTimestamp();
        $this->webhook($this->subEvent('evt_1', 'active', 'price_enterprisea', $t))->assertOk()->assertJsonPath('duplicate', false);
        $s = $this->sub();
        $this->assertSame(['active', 'stripe', 'enterprise', 'annual', 'sub_1'], [$s->status, $s->provider, $s->plan->code, $s->billing_interval, $s->stripe_subscription_id]);

        // The same event again is acknowledged but not applied.
        $s->forceFill(['status' => 'past_due'])->saveQuietly();
        $this->webhook($this->subEvent('evt_1', 'active', 'price_enterprisea', $t))->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('past_due', $this->sub()->status);
        $this->assertSame(1, BillingEvent::query()->where('event_id', 'evt_1')->count());

        // An older event that arrives late cannot overwrite newer state.
        $this->webhook($this->subEvent('evt_0', 'trialing', 'price_starterm', $t - 60))->assertOk()->assertJsonPath('outcome', 'ignored');
        $this->assertSame('enterprise', $this->sub()->plan->code);

        $this->webhook($this->subEvent('evt_2', 'past_due', 'price_enterprisea', $t + 1))->assertOk();
        $this->assertNotNull($this->sub()->past_due_since);
        $this->webhook($this->subEvent('evt_3', 'canceled', 'price_enterprisea', $t + 2, type: 'customer.subscription.deleted'))->assertOk();
        $this->assertSame('canceled', $this->sub()->status);
        $this->assertSame('read_only', $this->sub()->access()['mode']);

        // Another customer's subscription cannot attach itself to this organization.
        $this->webhook($this->subEvent('evt_4', 'active', 'price_growthm', $t + 3, ['id' => 'sub_x', 'customer' => 'cus_other']))->assertOk()->assertJsonPath('outcome', 'ignored');
        $this->assertSame('canceled', $this->sub()->status);
    }

    public function test_invoice_webhooks_record_payment_status(): void
    {
        $this->setSub(['provider' => 'stripe', 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1']);
        $invoice = fn (string $id, string $type, string $status, int $created) => ['id' => $id, 'type' => $type, 'created' => $created, 'data' => ['object' => [
            'id' => 'in_1', 'customer' => 'cus_1', 'number' => 'W-0001', 'status' => $status, 'currency' => 'usd', 'amount_due' => 4900, 'amount_paid' => $status === 'paid' ? 4900 : 0,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/1', 'invoice_pdf' => 'javascript:alert(1)', 'status_transitions' => ['finalized_at' => $created, 'paid_at' => $status === 'paid' ? $created : null],
        ]]];
        $t = now()->getTimestamp();
        $this->webhook($invoice('evt_i1', 'invoice.payment_failed', 'open', $t))->assertOk();
        $this->assertNotNull($this->sub()->past_due_since);
        $this->webhook($invoice('evt_i2', 'invoice.paid', 'paid', $t + 5))->assertOk();
        $this->webhook($invoice('evt_i3', 'invoice.updated', 'open', $t + 1))->assertOk()->assertJsonPath('outcome', 'ignored');
        $row = Invoice::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['paid', 4900, null], [$row->status, $row->amount_paid, $row->invoice_pdf_url]);
        $list = $this->apiAs($this->owner, 'GET', '/api/v1/billing/invoices')->assertOk()->json('data');
        $this->assertSame('W-0001', $list[0]['number']);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', 'billing.payment_failed')->where('tenant_id', $this->tenant->id)->exists());
        $other = $this->makeUser($this->makeTenant(), 'organization-owner');
        $this->assertSame([], $this->apiAs($other, 'GET', '/api/v1/billing/invoices')->json('data'));
    }

    public function test_upgrades_apply_now_and_downgrades_wait_for_the_period_end(): void
    {
        $end = now()->addDays(20)->startOfSecond();
        $this->setSub(['status' => 'active', 'provider' => 'stripe', 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'billing_interval' => 'monthly',
            'currency' => 'USD', 'current_period_end' => $end], 'growth');
        Http::fake(['*/subscriptions/sub_1' => Http::sequence()
            ->push(['id' => 'sub_1', 'items' => ['data' => [['id' => 'si_1']]]])->push(['id' => 'sub_1'])
            ->push(['id' => 'sub_1', 'items' => ['data' => [['id' => 'si_1']]]])->push(['id' => 'sub_1'])]);

        $v = $this->sub()->version;
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/change', ['plan' => 'enterprise', 'interval' => 'monthly', 'version' => $v + 9])->assertStatus(409);
        $up = $this->apiAs($this->owner, 'POST', '/api/v1/billing/change', ['plan' => 'enterprise', 'interval' => 'monthly', 'version' => $v])->assertOk()->json('data');
        $this->assertSame('enterprise', $up['plan']['code']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['proration_behavior'] === 'always_invoice' && $r['items'][0]['price'] === 'price_enterprisem');

        // Downgrade blocked when over the new plan's employee limit.
        Plan::query()->where('code', 'starter')->update(['employee_limit' => 1]);
        $this->makeEmployee($this->tenant);
        $this->makeEmployee($this->tenant);
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/change', ['plan' => 'starter', 'interval' => 'monthly', 'version' => $up['version']])->assertStatus(422);

        $down = $this->apiAs($this->owner, 'POST', '/api/v1/billing/change', ['plan' => 'growth', 'interval' => 'monthly', 'version' => $up['version']])->assertOk()->json('data');
        $this->assertSame('enterprise', $down['plan']['code']);
        $this->assertSame('growth', $down['pending_change']['plan']['code']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['proration_behavior'] === 'none' && $r['items'][0]['price'] === 'price_growthm');

        // Stripe reports the new price at once; entitlements stay on the higher plan until the period ends.
        $this->webhook($this->subEvent('evt_d', 'active', 'price_growthm', now()->getTimestamp()))->assertOk();
        $this->assertSame('enterprise', $this->sub()->effectivePlan()->code);
        $this->travelTo($end->copy()->addMinute());
        $this->assertSame('growth', $this->sub()->effectivePlan()->code);
    }

    public function test_cancel_and_resume(): void
    {
        $this->setSub(['status' => 'trialing', 'provider' => 'trial', 'trial_ends_at' => now()->addDays(5)]);
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/cancel')->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/resume')->assertOk()->assertJsonPath('data.status', 'trialing');

        $this->setSub(['status' => 'active', 'provider' => 'stripe', 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1']);
        Http::fake(['*/subscriptions/sub_1' => Http::response(['id' => 'sub_1'])]);
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/cancel')->assertOk()->assertJsonPath('data.cancel_at_period_end', true)->assertJsonPath('data.access.mode', 'full');
        Http::assertSent(fn (HttpRequest $r) => $r['cancel_at_period_end'] === 'true');
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/resume')->assertOk()->assertJsonPath('data.cancel_at_period_end', false);

        $this->setSub(['provider' => 'manual']);
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/cancel')->assertStatus(409);
    }

    public function test_without_stripe_keys_online_payments_are_unavailable(): void
    {
        config(['billing.stripe.secret' => null]);
        $this->setSub(['status' => 'trialing', 'provider' => 'trial', 'trial_ends_at' => now()->addDays(5)]);
        $this->assertFalse($this->apiAs($this->owner, 'GET', '/api/v1/billing/subscription')->json('data.payments_enabled'));
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertStatus(503);
        config(['billing.stripe.webhook_secret' => null]);
        $this->webhook($this->subEvent('evt_z', 'active', 'price_growthm', now()->getTimestamp()))->assertStatus(400);
    }

    public function test_platform_plan_catalog_is_platform_only_and_validated(): void
    {
        $owner = $this->makeUser(null, 'platform-owner');
        $admin = $this->makeUser(null, 'platform-admin');
        $this->apiAs($this->owner, 'GET', '/api/v1/platform/plans')->assertForbidden();
        $this->assertCount(3, $this->apiAs($admin, 'GET', '/api/v1/platform/plans')->assertOk()->json('data'));
        $this->apiAs($admin, 'POST', '/api/v1/platform/plans', ['code' => 'x', 'name' => 'X', 'features' => []])->assertForbidden();
        $this->apiAs($owner, 'POST', '/api/v1/platform/plans', ['code' => 'pro', 'name' => 'Pro', 'features' => ['payroll', 'telepathy']])->assertStatus(422)->assertJsonValidationErrors('features.1');
        $this->apiAs($owner, 'POST', '/api/v1/platform/plans', ['code' => 'pro', 'name' => 'Pro', 'features' => ['payroll'],
            'prices' => ['EUR' => ['monthly' => ['amount' => 1]]]])->assertStatus(422);
        $plan = $this->apiAs($owner, 'POST', '/api/v1/platform/plans', ['code' => 'pro', 'name' => 'Pro', 'features' => ['payroll'], 'employee_limit' => 50,
            'prices' => ['AED' => ['monthly' => ['amount' => 9900, 'stripe_price' => 'price_abc']]]])->assertCreated()->json('data');
        $this->apiAs($owner, 'PATCH', "/api/v1/platform/plans/{$plan['id']}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('event', 'platform.plan_created')->where('category', 'billing')->exists());
    }

    public function test_public_careers_site_follows_the_plan_and_billing_state(): void
    {
        $this->apiAs($this->hr, 'PATCH', '/api/v1/recruitment/settings', ['careers_enabled' => true, 'careers_slug' => 'acme-jobs', 'retention_days' => 90])->assertOk();
        $this->getJson('/api/v1/public/careers/acme-jobs')->assertOk();
        $this->setSub(['status' => 'trialing', 'provider' => 'trial', 'trial_ends_at' => now()->subDay()]);
        $this->getJson('/api/v1/public/careers/acme-jobs')->assertOk();
        $this->setSub(['status' => 'active', 'provider' => 'manual', 'trial_ends_at' => null], 'starter');
        $this->getJson('/api/v1/public/careers/acme-jobs')->assertNotFound();
    }

    public function test_an_upgrade_whose_payment_fails_does_not_change_the_plan(): void
    {
        $this->setSub(['status' => 'active', 'provider' => 'stripe', 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'billing_interval' => 'monthly', 'currency' => 'USD'], 'growth');
        Http::fake(['*/subscriptions/sub_1' => Http::sequence()->push(['id' => 'sub_1', 'items' => ['data' => [['id' => 'si_1']]]])->push(['id' => 'sub_1', 'pending_update' => ['expires_at' => now()->addDay()->getTimestamp()]])]);
        $v = $this->sub()->version;
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/change', ['plan' => 'enterprise', 'interval' => 'monthly', 'version' => $v])->assertStatus(402)->assertJsonPath('code', 'upgrade_payment_failed');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['payment_behavior'] === 'pending_if_incomplete');
        $this->assertSame('growth', $this->sub()->effectivePlan()->code);
        // The version was claimed before calling Stripe, so a second request with the same version is refused.
        $this->apiAs($this->owner, 'POST', '/api/v1/billing/change', ['plan' => 'enterprise', 'interval' => 'monthly', 'version' => $v])->assertStatus(409);
    }

    public function test_same_second_events_use_stripes_current_state(): void
    {
        $this->setSub(['status' => 'trialing', 'provider' => 'trial', 'trial_ends_at' => now()->addDays(3), 'stripe_customer_id' => 'cus_1'], 'growth');
        $t = now()->getTimestamp();
        $this->webhook($this->subEvent('evt_a', 'past_due', 'price_growthm', $t))->assertOk();
        $current = $this->subEvent('x', 'active', 'price_growthm', $t)['data']['object'];
        Http::fake(['*/subscriptions/sub_1' => Http::response($current)]);
        // Older update with the same second arrives last: the current Stripe state wins.
        $this->webhook($this->subEvent('evt_b', 'past_due', 'price_growthm', $t))->assertOk();
        $this->assertSame('active', $this->sub()->status);
    }

    public function test_a_second_checkout_cannot_replace_the_live_subscription(): void
    {
        $this->setSub(['status' => 'active', 'provider' => 'stripe', 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1'], 'growth');
        $session = fn (string $id, string $sub) => ['id' => $id, 'type' => 'checkout.session.completed', 'created' => now()->getTimestamp(), 'data' => ['object' => [
            'id' => 'cs_'.$id, 'mode' => 'subscription', 'customer' => 'cus_1', 'subscription' => $sub, 'client_reference_id' => $this->tenant->public_id,
            'metadata' => ['tenant' => $this->tenant->public_id, 'plan' => 'enterprise', 'interval' => 'annual']]]];
        $this->webhook($session('evt_c2', 'sub_2'))->assertOk()->assertJsonPath('outcome', 'ignored');
        $this->assertSame('sub_1', $this->sub()->stripe_subscription_id);
    }

    public function test_the_plan_bought_at_checkout_applies_when_its_price_was_rotated(): void
    {
        $this->setSub(['status' => 'trialing', 'provider' => 'trial', 'trial_ends_at' => now()->addDays(3)], 'growth');
        $t = now()->getTimestamp();
        $this->webhook(['id' => 'evt_s', 'type' => 'checkout.session.completed', 'created' => $t, 'data' => ['object' => [
            'id' => 'cs_1', 'mode' => 'subscription', 'customer' => 'cus_1', 'subscription' => 'sub_1', 'client_reference_id' => $this->tenant->public_id,
            'metadata' => ['tenant' => $this->tenant->public_id, 'plan' => 'enterprise', 'interval' => 'annual']]]])->assertOk();
        $this->webhook($this->subEvent('evt_r', 'active', 'price_retired', $t + 1))->assertOk();
        $s = $this->sub();
        $this->assertSame(['active', 'enterprise', 'annual'], [$s->status, $s->plan->code, $s->billing_interval]);
    }
}
