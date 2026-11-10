<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Security\EmployeeAccess;
use App\Security\Permission as P;

class EmployeePolicy
{
    use Concerns;

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && (
            $user->hasPermission(P::EmployeesViewAll)
            || $user->hasPermission(P::EmployeesViewDepartment)
            || $user->hasPermission(P::EmployeesViewSelf)
        );
    }

    public function view(User $user, Employee $employee): bool
    {
        return EmployeeAccess::canViewEmployee($user, $employee);
    }

    /** Assigning legal entity, work location and employment type. */
    public function assign(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee) && $user->hasPermission(P::EmployeesManage);
    }

    public function viewSensitive(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee) && $user->hasPermission(P::EmployeesSensitiveView);
    }

    public function viewCompensation(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee) && $user->hasPermission(P::PayrollView);
    }

    public function manageCompensation(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee) && $user->hasPermission(P::PayrollManage);
    }
}
