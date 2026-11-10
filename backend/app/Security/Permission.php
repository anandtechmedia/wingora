<?php

namespace App\Security;

enum Permission: string
{
    case PlatformTenantsView = 'platform.tenants.view';
    case PlatformTenantsManage = 'platform.tenants.manage';
    case PlatformStaffManage = 'platform.staff.manage';
    case PlatformAuditView = 'platform.audit.view';

    case OrganizationView = 'organization.view';
    case OrganizationManage = 'organization.manage';
    case OrganizationSecurityManage = 'organization.security.manage';
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
    case PayrollView = 'payroll.view';
    case PayrollManage = 'payroll.manage';
    case FilesUpload = 'files.upload';
    case AuditView = 'audit.view';
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
