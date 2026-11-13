<?php

namespace App\Performance;

use App\Models\Employee;
use App\Models\User;
use App\Security\Permission as P;
use App\Time\Approvers;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees and acts on whose performance records. Everyone with `performance.view` sees their own; managers with
 * `performance.review` see their reports (direct or by managed department); HR with `performance.manage` sees all.
 * Nobody assesses themselves as a manager would: manager steps always exclude the user's own employee record.
 */
final class PerformanceAccess
{
    public static function any(User $user): bool
    {
        foreach ([P::PerformanceView, P::PerformanceReview, P::PerformanceManage, P::PerformanceConfigure] as $p) {
            if ($user->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    /** Employees whose performance records the user may see. */
    public static function employees(User $user): Builder
    {
        return Approvers::visibleEmployees($user, P::PerformanceView, P::PerformanceReview, P::PerformanceManage);
    }

    public static function canView(User $user, Employee $employee): bool
    {
        return Approvers::canView($user, $employee, P::PerformanceView, P::PerformanceReview, P::PerformanceManage);
    }

    /** Manager-side actions: HR for anyone, managers for their reports, never for oneself. */
    public static function canManage(User $user, Employee $employee): bool
    {
        return Approvers::canDecide($user, $employee, P::PerformanceReview, P::PerformanceManage);
    }

    public static function isSelf(User $user, Employee $employee): bool
    {
        return $user->employee !== null && $user->employee->id === $employee->id && $user->hasPermission(P::PerformanceView);
    }

    /** May create or edit the employee's objectives: the employee, their manager, or HR. */
    public static function canEditObjectives(User $user, Employee $employee): bool
    {
        return self::isSelf($user, $employee) || self::canManage($user, $employee);
    }
}
