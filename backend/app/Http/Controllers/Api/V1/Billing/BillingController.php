<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Billing\Entitlements;
use App\Billing\Stripe\StripeClient;
use App\Billing\Subscriptions;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Security\Permission as P;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The organization's own subscription. Billing is for Organization Owners (billing.view / billing.manage). */
class BillingController extends Controller
{
    public function __construct(private readonly Subscriptions $subscriptions, private readonly Entitlements $entitlements, private readonly StripeClient $stripe) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::BillingView), 403);

        return response()->json(['data' => $this->present($this->current())]);
    }

    public function plans(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::BillingView), 403);
        $currency = Subscriptions::currencyFor(Tenancy::current());

        return response()->json(['data' => Plan::query()->where('active', true)->orderBy('rank')->get()->map(fn (Plan $p) => [
            'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'employee_limit' => $p->employee_limit,
            'ai_requests_monthly' => $p->ai_requests_monthly, 'features' => $p->features, 'rank' => $p->rank, 'currency' => $currency,
            'prices' => collect(Plan::INTERVALS)->mapWithKeys(fn ($i) => [$i => ['amount' => $p->amount($currency, $i), 'available' => $p->stripePrice($currency, $i) !== null]]),
        ])->values()]);
    }

    public function checkout(Request $request): JsonResponse
    {
        [$plan, $interval] = $this->choice($request);

        return response()->json(['data' => ['url' => $this->subscriptions->checkout($this->current(), $plan, $interval, $request->user())]]);
    }

    public function change(Request $request): JsonResponse
    {
        [$plan, $interval] = $this->choice($request, ['version' => ['required', 'integer']]);

        return response()->json(['data' => $this->present($this->subscriptions->change($this->current(), $plan, $interval, (int) $request->input('version')))]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->manage($request);

        return response()->json(['data' => $this->present($this->subscriptions->cancel($this->current()))]);
    }

    public function resume(Request $request): JsonResponse
    {
        $this->manage($request);

        return response()->json(['data' => $this->present($this->subscriptions->resume($this->current()))]);
    }

    public function portal(Request $request): JsonResponse
    {
        $this->manage($request);

        return response()->json(['data' => ['url' => $this->subscriptions->portal($this->current())]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::BillingView), 403);
        $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100'], 'status' => ['sometimes', Rule::in(['draft', 'open', 'paid', 'void', 'uncollectible'])]]);

        return response()->json(Invoice::query()->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('issued_at')->orderByDesc('id')->paginate((int) $request->input('per_page', 25))
            ->through(fn (Invoice $i) => [
                'id' => $i->public_id, 'number' => $i->number, 'status' => $i->status, 'currency' => $i->currency,
                'amount_due' => $i->amount_due, 'amount_paid' => $i->amount_paid, 'period_start' => $i->period_start?->toIso8601String(),
                'period_end' => $i->period_end?->toIso8601String(), 'issued_at' => $i->issued_at?->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(),
                'hosted_invoice_url' => $i->hosted_invoice_url, 'invoice_pdf_url' => $i->invoice_pdf_url,
            ]));
    }

    private function current(): Subscription
    {
        return $this->entitlements->subscription(Tenancy::current());
    }

    private function manage(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::BillingManage), 403);
    }

    /** @return array{0: Plan, 1: string} */
    private function choice(Request $request, array $extra = []): array
    {
        $this->manage($request);
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('active', true)],
            'interval' => ['required', Rule::in(Plan::INTERVALS)],
        ] + $extra);

        return [Plan::query()->where('code', $data['plan'])->firstOrFail(), $data['interval']];
    }

    public static function summary(Subscription $s): array
    {
        $plan = $s->effectivePlan();

        return [
            'plan' => ['code' => $plan->code, 'name' => $plan->name],
            'status' => $s->status,
            'access' => $s->access(),
            'features' => $plan->features,
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
        ];
    }

    private function present(Subscription $s): array
    {
        $tenant = Tenancy::current();
        $pending = $s->pending_plan_id && $s->plan_change_at?->isFuture() ? $s->pendingPlan : null;

        return self::summary($s) + [
            'provider' => $s->provider,
            'interval' => $s->billing_interval,
            'currency' => $s->currency ?? Subscriptions::currencyFor($tenant),
            'current_period_end' => $s->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $s->cancel_at_period_end,
            'canceled_at' => $s->canceled_at?->toIso8601String(),
            'grace_ends_at' => $s->status === 'past_due' ? $s->graceEndsAt()?->toIso8601String() : null,
            'pending_change' => $pending ? ['plan' => ['code' => $pending->code, 'name' => $pending->name], 'interval' => $s->pending_interval,
                'effective_at' => $s->plan_change_at->toIso8601String()] : null,
            'limits' => ['employees' => $s->effectivePlan()->employee_limit, 'ai_requests_monthly' => $s->effectivePlan()->ai_requests_monthly],
            'usage' => $this->entitlements->usage($tenant, $s),
            'payments_enabled' => $this->stripe->configured(),
            'has_payment_account' => $s->stripe_customer_id !== null,
            'version' => $s->version,
        ];
    }
}
