<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Audit\AuditCategory;
use App\Audit\AuditEvents;
use App\Billing\Entitlements;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\PayrollRun;
use App\Models\Subscription;
use App\Models\SupportGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Permission;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only views of an organization for Wingora support, available only while the organization's owner has an active
 * support grant. Every view is recorded in the organization's own audit log. Compensation, disciplinary records, salary
 * letters, documents and candidate files are never exposed; payroll run summaries only when the grant includes payroll.
 */
class SupportController extends Controller
{
    public const SECTIONS = ['overview', 'users', 'employees', 'audit', 'payroll'];

    public function status(Request $request, Tenant $tenant): JsonResponse
    {
        $this->staff($request);
        $grant = $this->grant($tenant);

        return response()->json(['data' => [
            'active' => $grant !== null,
            'expires_at' => $grant?->expires_at->toIso8601String(),
            'include_payroll' => (bool) $grant?->include_payroll,
            'reason' => $grant?->reason,
            'sections' => $grant ? array_values(array_filter(self::SECTIONS, fn ($s) => $s !== 'payroll' || $grant->include_payroll)) : [],
        ]]);
    }

    public function show(Request $request, Tenant $tenant, string $section, Entitlements $entitlements): JsonResponse
    {
        $this->staff($request);
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $grant = $this->grant($tenant) ?? abort(403, __('The organization has not granted support access.'));
        abort_if($section === 'payroll' && ! $grant->include_payroll, 403, __('The support access granted does not include payroll.'));

        $grant->forceFill(['last_used_at' => now()])->saveQuietly();
        AuditLog::record(AuditEvents::SUPPORT_ACCESSED, $grant, metadata: ['section' => $section, 'page' => (int) $request->input('page', 1)], tenantId: $tenant->id);

        return Tenancy::run($tenant, fn () => response()->json(match ($section) {
            'overview' => ['data' => [
                'organization' => ['id' => $tenant->public_id, 'name' => $tenant->name, 'country_code' => $tenant->country_code, 'status' => $tenant->status],
                'subscription' => ($s = Subscription::forTenant($tenant)) ? BillingController::summary($s) : null,
                'usage' => $entitlements->usage($tenant),
                'legal_entities' => LegalEntity::query()->orderBy('name')->get()->map(fn ($e) => ['id' => $e->public_id, 'name' => $e->name, 'country_code' => $e->country_code, 'currency_code' => $e->currency_code, 'status' => $e->status]),
                'departments' => Department::query()->count(),
                'employees_by_status' => Employee::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            ]],
            'users' => User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('roles')->orderBy('name')->paginate(25)
                ->through(fn (User $u) => ['name' => $u->name, 'email' => $u->email, 'status' => $u->status, 'roles' => $u->effectiveRoles()->pluck('key')->values(),
                    'mfa_enabled' => $u->hasMfaEnabled(), 'last_login_at' => $u->last_login_at?->toIso8601String()])->toArray(),
            'employees' => Employee::query()->with('department:id,name')->orderBy('last_name')->orderBy('id')->paginate(25)
                ->through(fn (Employee $e) => ['id' => $e->public_id, 'employee_number' => $e->employee_number, 'name' => trim($e->first_name.' '.$e->last_name),
                    'job_title' => $e->job_title, 'department' => $e->department?->name, 'status' => $e->status])->toArray(),
            'audit' => AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->when(! $grant->include_payroll, fn ($q) => $q->where('category', '!=', AuditCategory::Payroll->value))
                ->latest('id')->paginate(50)
                ->through(fn (AuditLog $l) => ['event' => $l->event, 'category' => $l->category instanceof AuditCategory ? $l->category->value : $l->category,
                    'outcome' => $l->outcome, 'created_at' => $l->created_at?->toIso8601String()])->toArray(),
            'payroll' => PayrollRun::query()->latest('id')->paginate(25)
                ->through(fn (PayrollRun $r) => ['id' => $r->public_id, 'type' => $r->type, 'status' => $r->status, 'currency' => $r->currency,
                    'employee_count' => $r->employee_count, 'created_at' => $r->created_at?->toIso8601String()])->toArray(),
        }));
    }

    private function staff(Request $request): void
    {
        abort_unless($request->user()->hasPermission(Permission::PlatformSupportAccess), 403);
    }

    private function grant(Tenant $tenant): ?SupportGrant
    {
        return SupportGrant::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->active()->latest('id')->first();
    }
}
