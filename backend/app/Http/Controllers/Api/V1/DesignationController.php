<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Designation;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DesignationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::OrganizationView), 403);

        return response()->json(['data' => Designation::query()->orderBy('name')->limit(500)->get()->map($this->present(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::DepartmentsManage), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:160', Rule::unique('designations')->where('tenant_id', $request->user()->tenant_id)]]);
        $designation = Designation::query()->create($data);

        return response()->json(['data' => $this->present($designation)], 201);
    }

    public function update(Request $request, Designation $designation): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::DepartmentsManage), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160', Rule::unique('designations')->where('tenant_id', $designation->tenant_id)->ignore($designation->id)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $designation->forceFill($data)->save();

        return response()->json(['data' => $this->present($designation)]);
    }

    private function present(Designation $d): array
    {
        return ['id' => $d->public_id, 'name' => $d->name, 'status' => $d->status];
    }
}
