<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Employee;
use App\Security\EmployeeAccess;
use App\Security\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Department::class);
        $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return DepartmentResource::collection(
            EmployeeAccess::scopeDepartments(Department::query(), $request->user())->orderBy('name')->paginate((int) $request->input('per_page', 25))
        );
    }

    public function show(Department $department): DepartmentResource
    {
        Gate::authorize('view', $department);

        return new DepartmentResource($department);
    }

    public function store(Request $request): DepartmentResource
    {
        abort_unless($request->user()->hasPermission(Permission::DepartmentsManage), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:160', Rule::unique('departments')->where('tenant_id', $request->user()->tenant_id)]]);

        return new DepartmentResource(Department::query()->create($data));
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        abort_unless($request->user()->hasPermission(Permission::DepartmentsManage), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160', Rule::unique('departments')->where('tenant_id', $department->tenant_id)->ignore($department->id)],
            'manager_employee_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);
        if (array_key_exists('manager_employee_id', $data)) {
            $data['manager_employee_id'] = $data['manager_employee_id'] === null ? null
                : (Employee::query()->where('public_id', $data['manager_employee_id'])->value('id')
                    ?? throw ValidationException::withMessages(['manager_employee_id' => __('The selected manager is invalid.')]));
        }
        $department->forceFill($data)->save();

        return new DepartmentResource($department);
    }
}
