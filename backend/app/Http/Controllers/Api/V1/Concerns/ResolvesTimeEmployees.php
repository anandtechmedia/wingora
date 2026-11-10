<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Employee;
use App\Models\User;
use App\Security\Permission as P;
use App\Time\Approvers;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

trait ResolvesTimeEmployees
{
    protected function self(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 409, __('Your account is not linked to an employee record.'));

        return $employee->loadMissing('workLocation');
    }

    /** Resolves `employee` (a public id) to a record the user may see; defaults to the user's own record. 404 otherwise. */
    protected function target(Request $request, ?string $publicId, P $view, P $approve, P $manage): Employee
    {
        if ($publicId === null) {
            $employee = $this->self($request);
            abort_unless($request->user()->hasPermission($view) || $request->user()->hasPermission($manage), 403);

            return $employee;
        }
        $employee = Approvers::visibleEmployees($request->user(), $view, $approve, $manage)->where('public_id', $publicId)->with('workLocation')->first();
        abort_unless($employee, 404);

        return $employee;
    }

    protected function canSeeAnyone(User $user, P $view, P $approve, P $manage): bool
    {
        return $user->hasPermission($view) || $user->hasPermission($approve) || $user->hasPermission($manage);
    }

    protected function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    protected function person(?Employee $e): ?array
    {
        return $e ? ['id' => $e->public_id, 'name' => trim(($e->preferred_name ?: $e->first_name).' '.$e->last_name), 'employee_number' => $e->employee_number] : null;
    }
}
