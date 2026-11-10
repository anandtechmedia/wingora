<?php

namespace App\Policies;

use App\Models\Payslip;
use App\Models\User;
use App\Security\Permission as P;

/** Payroll staff see every payslip; an employee sees only their own, and only once the run is published. */
class PayslipPolicy
{
    use Concerns;

    public function view(User $user, Payslip $payslip): bool
    {
        if (! $this->sameTenant($user, $payslip)) {
            return false;
        }
        if ($user->hasPermission(P::PayrollView)) {
            return true;
        }

        return $user->hasPermission(P::PayslipsViewSelf)
            && $user->employee !== null
            && (int) $payslip->employee_id === $user->employee->id
            && $payslip->run->status === 'published';
    }
}
