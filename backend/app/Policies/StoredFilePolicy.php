<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\StoredFile;
use App\Models\User;
use App\Security\EmployeeAccess;
use App\Security\Permission as P;

class StoredFilePolicy
{
    use Concerns;

    public function create(User $user, ?Employee $employee, string $category): bool
    {
        if ($user->tenant_id === null || ! $user->hasPermission(P::FilesUpload)) {
            return false;
        }

        if ($employee && ! $this->sameTenant($user, $employee)) {
            return false;
        }

        return match ($category) {
            'payroll' => $user->hasPermission(P::PayrollManage),
            'identity', 'contract' => $user->hasPermission(P::EmployeesManage) || ($employee && $user->employee?->id === $employee->id),
            default => $employee === null || EmployeeAccess::canViewEmployee($user, $employee),
        };
    }

    public function view(User $user, StoredFile $file): bool
    {
        if (! $this->sameTenant($user, $file)) {
            return false;
        }

        if ((int) $file->uploaded_by === (int) $user->id && $file->category !== 'payroll') {
            return true;
        }

        $isOwnFile = $file->employee_id !== null && $user->employee?->id === (int) $file->employee_id;

        return match ($file->category) {
            'payroll' => $user->hasPermission(P::PayrollView) || $isOwnFile,
            'identity', 'contract' => $user->hasPermission(P::EmployeesSensitiveView) || $isOwnFile,
            default => $file->employee_id === null
                ? $user->hasPermission(P::EmployeesViewAll)
                : ($file->employee && EmployeeAccess::canViewEmployee($user, $file->employee)),
        };
    }

    public function delete(User $user, StoredFile $file): bool
    {
        return $this->sameTenant($user, $file)
            && ($user->hasPermission(P::EmployeesManage) || (int) $file->uploaded_by === (int) $user->id);
    }
}
