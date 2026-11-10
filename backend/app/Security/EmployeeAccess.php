<?php

namespace App\Security;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Security\Permission as P;
use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level visibility of employees and departments. Used by both policies
 * (single records) and list endpoints (queries) so the two can never diverge.
 */
final class EmployeeAccess
{
    /** @return list<int> department ids the user manages */
    public static function managedDepartmentIds(User $user): array
    {
        if (! $user->hasPermission(P::EmployeesViewDepartment) || ! $user->employee) {
            return [];
        }

        return Department::query()->where('manager_employee_id', $user->employee->id)->pluck('id')->all();
    }

    public static function scopeEmployees(Builder $query, User $user): Builder
    {
        if ($user->hasPermission(P::EmployeesViewAll)) {
            return $query;
        }

        $departmentIds = self::managedDepartmentIds($user);
        $selfId = $user->hasPermission(P::EmployeesViewSelf) ? $user->employee?->id : null;

        if ($departmentIds === [] && $selfId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($departmentIds, $selfId) {
            $q->whereRaw('1 = 0');
            if ($departmentIds !== []) {
                $q->orWhereIn('department_id', $departmentIds);
            }
            if ($selfId !== null) {
                $q->orWhere($q->getModel()->qualifyColumn('id'), $selfId);
            }
        });
    }

    public static function canViewEmployee(User $user, Employee $employee): bool
    {
        if ($user->tenant_id === null || (int) $employee->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        if ($user->hasPermission(P::EmployeesViewAll)) {
            return true;
        }

        if ($user->hasPermission(P::EmployeesViewSelf) && $user->employee?->id === $employee->id) {
            return true;
        }

        return $employee->department_id !== null
            && in_array($employee->department_id, self::managedDepartmentIds($user), true);
    }

    public static function scopeDepartments(Builder $query, User $user): Builder
    {
        if ($user->hasPermission(P::DepartmentsView)) {
            return $query;
        }

        $ids = self::managedDepartmentIds($user);

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
    }

    public static function canViewDepartment(User $user, Department $department): bool
    {
        if ($user->tenant_id === null || (int) $department->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        return $user->hasPermission(P::DepartmentsView)
            || in_array($department->id, self::managedDepartmentIds($user), true);
    }
}
