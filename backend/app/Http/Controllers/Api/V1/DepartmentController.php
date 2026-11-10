<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Security\EmployeeAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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
}
