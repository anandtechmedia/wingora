<?php

namespace App\Security;

use App\Models\Role;
use App\Models\Tenant;
use App\Security\Permission as P;

/**
 * System roles. Their permissions are always resolved from this catalog, so a
 * tampered `roles.permissions` column cannot grant extra access.
 */
final class RoleCatalog
{
    public const PLATFORM_OWNER = 'platform-owner';

    public const PLATFORM_ADMIN = 'platform-admin';

    public const ORGANIZATION_OWNER = 'organization-owner';

    public const HR_ADMIN = 'hr-administrator';

    public const HR_MANAGER = 'hr-manager';

    public const DEPARTMENT_MANAGER = 'department-manager';

    public const EMPLOYEE = 'employee';

    public const PAYROLL_ADMIN = 'payroll-administrator';

    public const AUDITOR = 'auditor';

    /** @return array<string, array{name: string, scope: string, privileged: bool, permissions: list<P>}> */
    public static function definitions(): array
    {
        return [
            self::PLATFORM_OWNER => ['name' => 'Platform Owner', 'scope' => 'platform', 'privileged' => true, 'permissions' => [
                P::PlatformTenantsView, P::PlatformTenantsManage, P::PlatformStaffManage, P::PlatformAuditView,
                P::PlatformCountryRulesManage, P::PlatformCountryRulesApprove, P::PlatformAuditExport, P::PlatformBillingView, P::PlatformBillingManage,
            ]],
            self::PLATFORM_ADMIN => ['name' => 'Platform Administrator', 'scope' => 'platform', 'privileged' => true, 'permissions' => [
                P::PlatformTenantsView, P::PlatformTenantsManage, P::PlatformAuditView, P::PlatformCountryRulesManage, P::PlatformCountryRulesApprove, P::PlatformBillingView,
            ]],
            self::ORGANIZATION_OWNER => ['name' => 'Organization Owner', 'scope' => 'tenant', 'privileged' => true, 'permissions' => P::tenantPermissions()],
            self::HR_ADMIN => ['name' => 'HR Administrator', 'scope' => 'tenant', 'privileged' => true, 'permissions' => [
                P::OrganizationView, P::UsersView, P::UsersManage, P::RolesAssign, P::DepartmentsView, P::DepartmentsManage,
                P::EmployeesViewAll, P::EmployeesManage, P::EmployeesSensitiveView, P::FilesUpload, P::AuditView,
                P::LegalEntitiesManage, P::HolidaysManage,
                P::EmployeesDisciplinaryView, P::EmployeesDisciplinaryManage, P::EmployeesImport, P::EmployeesExport, P::PoliciesManage,
                P::AttendanceView, P::AttendanceClock, P::LeaveView, P::LeaveApply, P::AttendanceApprove, P::AttendanceManage, P::AttendanceConfigure, P::LeaveApprove, P::LeaveManage, P::LeaveConfigure,
                P::PayslipsViewSelf, P::ReimbursementsSubmit,
                P::RecruitmentView, P::RecruitmentManage, P::RecruitmentConfigure, P::RecruitmentOffers, P::RecruitmentInterview, P::OnboardingView, P::OnboardingManage, P::OnboardingConfigure, P::OnboardingTasksSelf,
                P::HelpdeskSubmit, P::HelpdeskAgent, P::HelpdeskManage, P::HelpdeskConfigure,
                P::DocumentsRequest, P::DocumentsManage, P::DocumentsConfigure,
            ]],
            self::HR_MANAGER => ['name' => 'HR Manager', 'scope' => 'tenant', 'privileged' => true, 'permissions' => [
                P::OrganizationView, P::UsersView, P::DepartmentsView, P::EmployeesViewAll, P::EmployeesManage, P::FilesUpload, P::HolidaysManage,
                P::EmployeesDisciplinaryView, P::EmployeesDisciplinaryManage, P::EmployeesExport,
                P::AttendanceView, P::AttendanceClock, P::LeaveView, P::LeaveApply, P::AttendanceApprove, P::AttendanceManage, P::LeaveApprove, P::LeaveManage,
                P::PayslipsViewSelf, P::ReimbursementsSubmit,
                P::RecruitmentView, P::RecruitmentManage, P::RecruitmentInterview, P::OnboardingView, P::OnboardingManage, P::OnboardingTasksSelf,
                P::HelpdeskSubmit, P::HelpdeskAgent, P::HelpdeskManage, P::DocumentsRequest, P::DocumentsManage,
            ]],
            self::DEPARTMENT_MANAGER => ['name' => 'Department Manager', 'scope' => 'tenant', 'privileged' => false, 'permissions' => [
                P::OrganizationView, P::EmployeesViewDepartment, P::EmployeesViewSelf, P::FilesUpload,
                P::AttendanceView, P::AttendanceClock, P::LeaveView, P::LeaveApply, P::AttendanceApprove, P::LeaveApprove,
                P::PayslipsViewSelf, P::ReimbursementsSubmit, P::ReimbursementsApprove,
                P::RecruitmentView, P::RecruitmentInterview, P::OnboardingTasksSelf, P::HelpdeskSubmit, P::DocumentsRequest, P::DocumentsApprove,
            ]],
            self::EMPLOYEE => ['name' => 'Employee', 'scope' => 'tenant', 'privileged' => false, 'permissions' => [
                P::OrganizationView, P::EmployeesViewSelf, P::FilesUpload, P::AttendanceView, P::AttendanceClock, P::LeaveView, P::LeaveApply,
                P::PayslipsViewSelf, P::ReimbursementsSubmit,
                P::RecruitmentInterview, P::OnboardingTasksSelf, P::HelpdeskSubmit, P::DocumentsRequest,
            ]],
            self::PAYROLL_ADMIN => ['name' => 'Payroll Administrator', 'scope' => 'tenant', 'privileged' => true, 'permissions' => [
                P::OrganizationView, P::EmployeesViewAll, P::EmployeesSensitiveView, P::PayrollView, P::PayrollManage, P::FilesUpload, P::AttendanceView, P::AttendanceClock, P::LeaveView, P::LeaveApply,
                P::PayrollConfigure, P::PayrollApprove, P::PayrollExport, P::PayslipsViewSelf, P::ReimbursementsSubmit, P::ReimbursementsApprove,
                P::HelpdeskSubmit, P::DocumentsRequest, P::DocumentsSalary,
            ]],
            self::AUDITOR => ['name' => 'Auditor', 'scope' => 'tenant', 'privileged' => true, 'permissions' => [
                P::OrganizationView, P::UsersView, P::AuditView, P::AuditExport, P::SecurityEventsView,
            ]],
        ];
    }

    /** @return list<P> */
    public static function permissionsFor(string $key): array
    {
        return self::definitions()[$key]['permissions'] ?? [];
    }

    public static function syncPlatformRoles(): void
    {
        foreach (self::definitions() as $key => $def) {
            if ($def['scope'] === 'platform') {
                self::upsert(null, $key, $def);
            }
        }
    }

    public static function syncTenantRoles(Tenant $tenant): void
    {
        foreach (self::definitions() as $key => $def) {
            if ($def['scope'] === 'tenant') {
                self::upsert($tenant->id, $key, $def);
            }
        }
    }

    private static function upsert(?int $tenantId, string $key, array $def): void
    {
        $role = Role::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->first() ?? new Role;
        $role->forceFill([
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => $def['name'],
            'scope' => $def['scope'],
            'is_system' => true,
            'is_privileged' => $def['privileged'],
            'permissions' => null,
        ])->save();
    }
}
