<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Security\EmployeeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Read-only endpoints that exist to prove row-level authorization; HR workflows come later. */
class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $query = EmployeeAccess::scopeEmployees(Employee::query()->with('department'), $request->user())
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('first_name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('last_name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderBy('last_name')->orderBy('first_name');

        return EmployeeResource::collection($query->paginate((int) ($data['per_page'] ?? 25)));
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee->load('department'));
    }

    public function sensitive(Employee $employee): JsonResponse
    {
        Gate::authorize('viewSensitive', $employee);
        AuditLog::record('employee.sensitive_viewed', $employee);

        return response()->json(['data' => [
            'id' => $employee->public_id,
            'national_id' => $employee->national_id,
            'bank_account_number' => $employee->bank_account_number,
            'date_of_birth' => $employee->date_of_birth,
        ]]);
    }

    public function compensation(Employee $employee): JsonResponse
    {
        Gate::authorize('viewCompensation', $employee);
        $compensation = $employee->compensation()->firstOrFail();
        AuditLog::record('compensation.viewed', $compensation);

        return response()->json(['data' => [
            'employee_id' => $employee->public_id,
            'currency' => $compensation->currency,
            'base_salary' => $compensation->base_salary,
            'pay_frequency' => $compensation->pay_frequency,
            'effective_date' => $compensation->effective_date->toDateString(),
        ]]);
    }
}
