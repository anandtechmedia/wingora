<?php

namespace Tests\Feature\Security;

use App\Models\Tenant;
use App\Security\RoleCatalog;

class AuthorizationTest extends SecurityTestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
    }

    public function test_employee_sees_only_own_record_and_no_sensitive_or_pay_data(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $dept = $this->makeDepartment($this->tenant, 'Sales');
        $self = $this->makeEmployee($this->tenant, $dept, $user, ['bank_account_number' => '000123']);
        $colleague = $this->makeEmployee($this->tenant, $dept);
        $this->makeCompensation($self);

        $this->apiAs($user, 'GET', "/api/v1/employees/{$self->public_id}")->assertOk()->assertJsonMissingPath('data.bank_account_number');
        $this->apiAs($user, 'GET', "/api/v1/employees/{$colleague->public_id}")->assertForbidden();
        $this->apiAs($user, 'GET', '/api/v1/employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $self->public_id);
        $this->apiAs($user, 'GET', "/api/v1/employees/{$self->public_id}/sensitive")->assertForbidden();
        $this->apiAs($user, 'GET', "/api/v1/employees/{$self->public_id}/compensation")->assertForbidden();
        $this->apiAs($user, 'GET', '/api/v1/departments')->assertForbidden();
        $this->apiAs($user, 'GET', '/api/v1/users')->assertForbidden();
        $this->apiAs($user, 'GET', '/api/v1/audit-logs')->assertForbidden();
        $this->apiAs($user, 'GET', '/api/v1/security-events')->assertForbidden();
    }

    public function test_department_manager_only_sees_managed_departments(): void
    {
        $managerUser = $this->makeUser($this->tenant, RoleCatalog::DEPARTMENT_MANAGER);
        $managerEmp = $this->makeEmployee($this->tenant, null, $managerUser);
        $engineering = $this->makeDepartment($this->tenant, 'Engineering', $managerEmp);
        $finance = $this->makeDepartment($this->tenant, 'Finance');
        $report = $this->makeEmployee($this->tenant, $engineering);
        $outsider = $this->makeEmployee($this->tenant, $finance);
        $this->makeCompensation($report);

        $this->apiAs($managerUser, 'GET', '/api/v1/departments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $engineering->public_id);
        $this->apiAs($managerUser, 'GET', "/api/v1/departments/{$engineering->public_id}")->assertOk();
        $this->apiAs($managerUser, 'GET', "/api/v1/departments/{$finance->public_id}")->assertForbidden();
        $this->apiAs($managerUser, 'GET', "/api/v1/employees/{$report->public_id}")->assertOk();
        $this->apiAs($managerUser, 'GET', "/api/v1/employees/{$outsider->public_id}")->assertForbidden();

        $ids = collect($this->apiAs($managerUser, 'GET', '/api/v1/employees')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$report->public_id, $managerEmp->public_id], $ids->all());

        $this->apiAs($managerUser, 'GET', "/api/v1/employees/{$report->public_id}/compensation")->assertForbidden();
        $this->apiAs($managerUser, 'GET', "/api/v1/employees/{$report->public_id}/sensitive")->assertForbidden();
    }

    public function test_payroll_data_is_restricted_to_payroll_permissions(): void
    {
        $emp = $this->makeEmployee($this->tenant, $this->makeDepartment($this->tenant, 'Ops'));
        $this->makeCompensation($emp, '120000.00');
        $uri = "/api/v1/employees/{$emp->public_id}/compensation";

        foreach ([RoleCatalog::HR_ADMIN, RoleCatalog::HR_MANAGER, RoleCatalog::AUDITOR, RoleCatalog::DEPARTMENT_MANAGER, RoleCatalog::EMPLOYEE] as $role) {
            $this->apiAs($this->makeUser($this->tenant, $role), 'GET', $uri)->assertForbidden();
        }

        foreach ([RoleCatalog::PAYROLL_ADMIN, RoleCatalog::ORGANIZATION_OWNER] as $role) {
            $this->apiAs($this->makeUser($this->tenant, $role), 'GET', $uri)->assertOk()->assertJsonPath('data.base_salary', '120000.00');
        }

        $this->assertDatabaseHas('audit_logs', ['event' => 'compensation.viewed']);
    }

    public function test_platform_staff_cannot_read_tenant_hr_data(): void
    {
        $emp = $this->makeEmployee($this->tenant);
        foreach ([RoleCatalog::PLATFORM_OWNER, RoleCatalog::PLATFORM_ADMIN] as $role) {
            $staff = $this->makeUser(null, $role);
            $this->apiAs($staff, 'GET', '/api/v1/employees')->assertForbidden();
            $this->apiAs($staff, 'GET', "/api/v1/employees/{$emp->public_id}")->assertForbidden();
            $this->apiAs($staff, 'GET', '/api/v1/platform/tenants')->assertOk();
        }
    }

    public function test_tenant_users_cannot_use_platform_endpoints(): void
    {
        $owner = $this->makeUser($this->tenant, RoleCatalog::ORGANIZATION_OWNER);
        $this->apiAs($owner, 'GET', '/api/v1/platform/tenants')->assertForbidden();
        $this->apiAs($owner, 'POST', '/api/v1/platform/tenants', [])->assertForbidden();
    }

    public function test_auditor_reads_logs_but_not_hr_records(): void
    {
        $auditor = $this->makeUser($this->tenant, RoleCatalog::AUDITOR);
        $emp = $this->makeEmployee($this->tenant);

        $this->apiAs($auditor, 'GET', '/api/v1/audit-logs')->assertOk();
        $this->apiAs($auditor, 'GET', '/api/v1/security-events')->assertOk();
        $this->apiAs($auditor, 'GET', "/api/v1/employees/{$emp->public_id}")->assertForbidden();
        $this->apiAs($auditor, 'PATCH', '/api/v1/organization', ['name' => 'X'])->assertForbidden();
    }

    public function test_privilege_escalation_through_role_assignment_is_blocked(): void
    {
        $hrAdmin = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN);
        $target = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);

        $this->apiAs($hrAdmin, 'PUT', "/api/v1/users/{$target->public_id}/roles", ['roles' => ['payroll-administrator']])->assertForbidden();
        $this->apiAs($hrAdmin, 'PUT', "/api/v1/users/{$target->public_id}/roles", ['roles' => ['organization-owner']])->assertForbidden();
        $this->apiAs($hrAdmin, 'PUT', "/api/v1/users/{$hrAdmin->public_id}/roles", ['roles' => ['hr-administrator', 'auditor']])->assertForbidden();
        $this->apiAs($hrAdmin, 'PUT', "/api/v1/users/{$target->public_id}/roles", ['roles' => ['platform-owner']])->assertUnprocessable();
        $this->apiAs($hrAdmin, 'PUT', "/api/v1/users/{$target->public_id}/roles", ['roles' => ['hr-manager']])->assertOk()->assertJsonPath('data.roles', ['hr-manager']);
        $this->assertDatabaseHas('security_events', ['type' => 'authz.role_escalation_blocked']);
    }

    public function test_privileged_roles_must_enrol_mfa_before_accessing_data(): void
    {
        $hr = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN, mfa: false);
        $this->apiAs($hr, 'GET', '/api/v1/employees')->assertForbidden()->assertJsonPath('title', 'MFA enrollment required');
        $this->apiAs($hr, 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('mfa_enrollment_required', true);

        $employee = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE, mfa: false);
        $this->makeEmployee($this->tenant, null, $employee);
        $this->apiAs($employee, 'GET', '/api/v1/employees')->assertOk();
    }

    public function test_tenant_can_require_mfa_for_every_user(): void
    {
        $this->tenant->forceFill(['mfa_required' => true])->save();
        $employee = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE, mfa: false);
        $this->apiAs($employee, 'GET', '/api/v1/employees')->assertForbidden();
    }
}
