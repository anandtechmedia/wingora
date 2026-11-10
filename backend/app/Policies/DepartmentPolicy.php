<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Security\EmployeeAccess;
use App\Security\Permission as P;

class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null
            && ($user->hasPermission(P::DepartmentsView) || $user->hasPermission(P::EmployeesViewDepartment));
    }

    public function view(User $user, Department $department): bool
    {
        return EmployeeAccess::canViewDepartment($user, $department);
    }
}
