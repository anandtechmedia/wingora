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

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::EmployeesManage);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee) && $user->hasPermission(P::EmployeesManage);
    }

    /** Personal contact details and emergency contacts: the employee themselves and HR only (not line managers). */
    public function viewPersonal(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee)
            && ($this->isSelf($user, $employee) || $user->hasPermission(P::EmployeesManage) || $user->hasPermission(P::EmployeesSensitiveView));
    }

    public function updatePersonal(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee) && ($this->isSelf($user, $employee) || $user->hasPermission(P::EmployeesManage));
    }

    public function updateSensitive(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee) && $user->hasPermission(P::EmployeesManage) && $user->hasPermission(P::EmployeesSensitiveView);
    }

    /** Notes and disciplinary records are never shown to the employee they concern, whatever their role. */
    public function viewRecords(User $user, Employee $employee): bool
    {
        return $this->sameTenant($user, $employee) && ! $this->isSelf($user, $employee) && $user->hasPermission(P::EmployeesDisciplinaryView);
    }

    public function manageRecords(User $user, Employee $employee): bool
    {
        return $this->viewRecords($user, $employee) && $user->hasPermission(P::EmployeesDisciplinaryManage);
    }

    public function import(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::EmployeesImport) && $user->hasPermission(P::EmployeesManage);
    }

    public function export(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::EmployeesExport);
    }

    private function isSelf(User $user, Employee $employee): bool
    {
        return $user->employee !== null && $user->employee->id === $employee->id;
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
