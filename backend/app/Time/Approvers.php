<?php

namespace App\Time;

use App\Models\Employee;
use App\Models\User;
use App\Security\EmployeeAccess;
use App\Security\Permission as P;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and decide attendance and leave items. "Manage" holders (HR) cover the whole organization;
 * "approve" holders cover their direct reports and the departments they manage. Nobody decides their own items.
 */
final class Approvers
{
    public static function isManagerOf(User $user, Employee $employee): bool
    {
        $me = $user->employee;
        if (! $me || $me->id === $employee->id) {
            return false;
        }

        return (int) $employee->manager_id === $me->id
            || ($employee->department_id !== null && in_array($employee->department_id, EmployeeAccess::managedDepartmentIds($user), true));
    }

    public static function canDecide(User $user, Employee $employee, P $approve, P $manage): bool
    {
        if ($user->tenant_id === null || (int) $employee->tenant_id !== (int) $user->tenant_id || $user->employee?->id === $employee->id) {
            return false;
        }

        return $user->hasPermission($manage) || ($user->hasPermission($approve) && self::isManagerOf($user, $employee));
    }

    public static function canView(User $user, Employee $employee, P $view, P $approve, P $manage): bool
    {
        if ($user->tenant_id === null || (int) $employee->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        return $user->hasPermission($manage)
            || ($user->hasPermission($view) && $user->employee?->id === $employee->id)
            || ($user->hasPermission($approve) && self::isManagerOf($user, $employee));
    }

    /** Employees whose items the user may see. */
    public static function visibleEmployees(User $user, P $view, P $approve, P $manage): Builder
    {
        $query = Employee::query();
        if ($user->hasPermission($manage)) {
            return $query;
        }

        $me = $user->employee;
        $self = $me && $user->hasPermission($view) ? $me->id : null;
        $team = $me && $user->hasPermission($approve);
        $departments = $team ? EmployeeAccess::managedDepartmentIds($user) : [];

        return $query->where(function (Builder $q) use ($self, $team, $me, $departments) {
            $q->whereRaw('1 = 0');
            if ($self !== null) {
                $q->orWhere('employees.id', $self);
            }
            if ($team) {
                $q->orWhere('employees.manager_id', $me->id);
                if ($departments !== []) {
                    $q->orWhereIn('employees.department_id', $departments);
                }
            }
        });
    }
}
