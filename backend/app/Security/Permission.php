<?php

namespace App\Security;

enum Permission: string
{
    case PlatformTenantsView = 'platform.tenants.view';
    case PlatformTenantsManage = 'platform.tenants.manage';
    case PlatformStaffManage = 'platform.staff.manage';
    case PlatformAuditView = 'platform.audit.view';
    case PlatformAuditExport = 'platform.audit.export';
    case PlatformCountryRulesManage = 'platform.country-rules.manage';
    case PlatformCountryRulesApprove = 'platform.country-rules.approve';

    case OrganizationView = 'organization.view';
    case OrganizationManage = 'organization.manage';
    case OrganizationSecurityManage = 'organization.security.manage';
    case LegalEntitiesManage = 'organization.entities.manage';
    case HolidaysManage = 'holidays.manage';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesAssign = 'roles.assign';
    case DepartmentsView = 'departments.view';
    case DepartmentsManage = 'departments.manage';
    case EmployeesViewAll = 'employees.view.all';
    case EmployeesViewDepartment = 'employees.view.department';
    case EmployeesViewSelf = 'employees.view.self';
    case EmployeesManage = 'employees.manage';
    case EmployeesSensitiveView = 'employees.sensitive.view';
    case EmployeesDisciplinaryView = 'employees.disciplinary.view';
    case EmployeesDisciplinaryManage = 'employees.disciplinary.manage';
    case EmployeesImport = 'employees.import';
    case EmployeesExport = 'employees.export';
    case PoliciesManage = 'policies.manage';
    case AttendanceView = 'attendance.view';
    case AttendanceClock = 'attendance.clock';
    case AttendanceApprove = 'attendance.approve';
    case AttendanceManage = 'attendance.manage';
    case AttendanceConfigure = 'attendance.configure';
    case LeaveView = 'leave.view';
    case LeaveApply = 'leave.apply';
    case LeaveApprove = 'leave.approve';
    case LeaveManage = 'leave.manage';
    case LeaveConfigure = 'leave.configure';
    case PayrollView = 'payroll.view';
    case PayrollManage = 'payroll.manage';
    case PayrollConfigure = 'payroll.configure';
    case PayrollApprove = 'payroll.approve';
    case PayrollExport = 'payroll.export';
    case PayslipsViewSelf = 'payslips.view.self';
    case ReimbursementsSubmit = 'reimbursements.submit';
    case ReimbursementsApprove = 'reimbursements.approve';
    case FilesUpload = 'files.upload';
    case AuditView = 'audit.view';
    case AuditExport = 'audit.export';
    case AuditRetentionManage = 'audit.retention.manage';
    case SecurityEventsView = 'security-events.view';

    public function isPlatform(): bool
    {
        return str_starts_with($this->value, 'platform.');
    }

    /** @return list<self> */
    public static function tenantPermissions(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => ! $p->isPlatform()));
    }
}
