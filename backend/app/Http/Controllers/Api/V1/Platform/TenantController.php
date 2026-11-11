<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Audit\AuditEvents;
use App\Billing\Entitlements;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DeletionRequest;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Subscription;
use App\Models\SupportGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Security\LoginService;
use App\Security\Permission;
use App\Security\RoleCatalog;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Platform staff manage organizations and their subscriptions, but never read organization HR data (see SupportController). */
class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'deleted'])],
            'plan' => ['sometimes', 'string', 'max:40'],
            'subscription_status' => ['sometimes', Rule::in(Subscription::STATUSES)],
        ]);
        $like = isset($data['search']) ? '%'.addcslashes($data['search'], '%_\\').'%' : null;

        return response()->json(Tenant::query()->with('subscription.plan')
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like)))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v), fn ($q) => $q->where('status', '!=', 'deleted'))
            ->when($data['plan'] ?? null, fn ($q, $v) => $q->whereHas('subscription.plan', fn ($p) => $p->where('code', $v)))
            ->when($data['subscription_status'] ?? null, fn ($q, $v) => $q->whereHas('subscription', fn ($p) => $p->withoutGlobalScopes()->where('status', $v)))
            ->orderBy('name')->orderBy('id')->paginate((int) ($data['per_page'] ?? 25))
            ->through(fn (Tenant $t) => $this->present($t)));
    }

    public function show(Tenant $tenant, Entitlements $entitlements): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);
        $subscription = Subscription::forTenant($tenant);
        $deletion = DeletionRequest::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'scheduled')->first();
        $grant = SupportGrant::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->active()->latest('id')->first();

        return response()->json(['data' => [
            'subscription' => $subscription ? BillingController::summary($subscription) + [
                'provider' => $subscription->provider, 'billing_interval' => $subscription->billing_interval, 'currency' => $subscription->currency,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(), 'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
                'version' => $subscription->version,
            ] : null,
        ] + $this->present($tenant) + [
            'users' => User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'usage' => $subscription ? $entitlements->usage($tenant, $subscription) : null,
            'support_access' => $grant ? ['expires_at' => $grant->expires_at->toIso8601String(), 'include_payroll' => $grant->include_payroll] : null,
            'deletion_scheduled_for' => $deletion?->scheduled_for->toIso8601String(),
            'legal_hold' => (bool) $tenant->audit_legal_hold,
        ]]);
    }

    /** Manual billing for invoiced customers, and trial extensions. Stripe-billed subscriptions are changed in Stripe. */
    public function updateSubscription(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permission::PlatformBillingManage), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['extend_trial', 'assign_plan'])],
            'days' => ['required_if:action,extend_trial', 'integer', 'between:1,90'],
            'plan' => ['required_if:action,assign_plan', 'string', Rule::exists('plans', 'code')],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:today'],
            'reason' => ['required', 'string', 'max:500'],
            'version' => ['required', 'integer'],
        ]);
        $subscription = Subscription::forTenant($tenant) ?? abort(404);
        abort_if($subscription->version !== (int) $data['version'], 409, __('The subscription changed. Reload and try again.'));
        $old = ['plan' => $subscription->plan->code, 'status' => $subscription->status, 'provider' => $subscription->provider, 'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()];

        if ($data['action'] === 'extend_trial') {
            abort_unless($subscription->provider === 'trial', 409, __('Only a free trial can be extended.'));
            $from = $subscription->trial_ends_at && $subscription->trial_ends_at->isFuture() ? $subscription->trial_ends_at : now();
            $subscription->forceFill(['status' => 'trialing', 'trial_ends_at' => $from->copy()->addDays((int) $data['days'])]);
        } else {
            abort_if($subscription->provider === 'stripe' && $subscription->status !== 'canceled', 409, __('This organization pays through Stripe. Change the plan in Stripe or ask the owner to change it.'));
            $subscription->forceFill(['provider' => 'manual', 'status' => 'active', 'plan_id' => Plan::query()->where('code', $data['plan'])->value('id'),
                'pending_plan_id' => null, 'pending_interval' => null, 'plan_change_at' => null, 'cancel_at_period_end' => false, 'canceled_at' => null,
                'trial_ends_at' => null, 'current_period_end' => $data['ends_at'] ?? null]);
        }
        $subscription->forceFill(['version' => $subscription->version + 1])->saveQuietly();
        $subscription->refresh()->load('plan');
        AuditLog::record(AuditEvents::PLATFORM_SUBSCRIPTION_CHANGED, $subscription, $old,
            ['plan' => $subscription->plan->code, 'status' => $subscription->status, 'provider' => $subscription->provider, 'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()],
            ['action' => $data['action'], 'reason' => $data['reason']], tenantId: $tenant->id);

        return $this->show($tenant, app(Entitlements::class));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Tenant::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'unique:tenants,slug'],
            'country_code' => ['required', 'string', Rule::in(config('security.countries'))],
            'owner_name' => ['required', 'string', 'max:160'],
            'owner_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        $tenant = DB::transaction(function () use ($data) {
            $tenant = Tenant::create(['name' => $data['name'], 'slug' => $data['slug'], 'country_code' => $data['country_code']]);
            RoleCatalog::syncTenantRoles($tenant);

            Tenancy::run($tenant, function () use ($data) {
                $owner = User::create(['name' => $data['owner_name'], 'email' => Str::lower($data['owner_email']), 'password' => Str::password(40)]);
                $owner->roles()->attach(Role::query()->where('key', RoleCatalog::ORGANIZATION_OWNER)->value('id'), ['created_at' => now()]);
            });

            return $tenant;
        });

        Password::sendResetLink(['email' => Str::lower($data['owner_email'])]);
        SecurityEvent::record('platform.tenant_created', 'warning', request()->user(), ['tenant' => $tenant->public_id], $tenant->id);

        return response()->json(['data' => $this->present($tenant)], 201);
    }

    public function updateStatus(Request $request, Tenant $tenant, LoginService $logins): JsonResponse
    {
        Gate::authorize('changeStatus', $tenant);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'reason' => ['required', 'string', 'max:500']]);
        abort_if($tenant->status === 'deleted', 409, __('This organization has been deleted.'));

        $old = $tenant->status;
        $tenant->forceFill(['status' => $data['status']])->save();

        if ($data['status'] === 'suspended') {
            User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->each(fn (User $u) => $logins->revokeEverything($u));
        }

        AuditLog::record(AuditEvents::PLATFORM_TENANT_STATUS_CHANGED, $tenant, ['status' => $old], ['status' => $data['status']], ['reason' => $data['reason']]);
        SecurityEvent::record('platform.tenant_status_changed', 'warning', $request->user(), ['tenant' => $tenant->public_id, 'status' => $data['status']], $tenant->id, audit: false);

        return response()->json(['data' => $this->present($tenant)]);
    }

    private function present(Tenant $tenant): array
    {
        return [
            'id' => $tenant->public_id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'country_code' => $tenant->country_code,
            'status' => $tenant->status,
            'subscription' => ($sub = $tenant->subscription) ? ['plan' => $sub->plan?->code, 'status' => $sub->status, 'provider' => $sub->provider,
                'access' => $sub->access()['mode'], 'trial_ends_at' => $sub->trial_ends_at?->toIso8601String()] : null,
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
