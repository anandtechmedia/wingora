<?php

namespace App\Http\Controllers\Api\V1\Performance;

use App\Models\Employee;
use App\Performance\PerformanceAccess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

trait Concerns
{
    protected function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    protected function person(?Employee $e): ?array
    {
        return $e ? ['id' => $e->public_id, 'name' => trim(($e->preferred_name ?: $e->first_name).' '.$e->last_name), 'employee_number' => $e->employee_number] : null;
    }

    /** Resolves an employee the user may see by public id, or fails as not found so ids cannot be probed. */
    protected function visibleEmployee(Request $request, string $publicId): Employee
    {
        return PerformanceAccess::employees($request->user())->where('public_id', $publicId)->firstOr(fn () => abort(404));
    }

    protected function self(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 409, __('Your account is not linked to an employee record.'));

        return $employee;
    }

    /** Applies scope=mine|team|all to a query on an employee column. */
    protected function scoped($query, Request $request, string $scope, string $column = 'employee_id')
    {
        $user = $request->user();
        $me = $user->employee?->id;

        return match ($scope) {
            'mine' => $query->where($column, $me ?? 0),
            'team' => $query->whereIn($column, PerformanceAccess::employees($user)->where('employees.id', '!=', $me ?? 0)->select('employees.id')),
            default => $query->whereIn($column, PerformanceAccess::employees($user)->select('employees.id')),
        };
    }

    protected function checkVersion(object $model, int $version): void
    {
        abort_if((int) $model->version !== $version, 409, __('This record was changed by someone else. Reload and try again.'));
    }
}
