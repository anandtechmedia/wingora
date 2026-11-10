<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** HR notes and disciplinary records. Requires employees.disciplinary.*; never available to the employee concerned. */
class EmployeeRecordController extends Controller
{
    public function index(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('viewRecords', $employee);
        $data = $request->validate(['type' => ['sometimes', Rule::in(EmployeeRecord::TYPES)], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = $employee->records()->with('author:id,name')->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('occurred_on')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));
        AuditLog::record('employee.hr_records_viewed', $employee, metadata: ['type' => $data['type'] ?? 'all']);

        return response()->json([
            'data' => $page->getCollection()->map($this->present(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('manageRecords', $employee);
        $data = $request->validate([
            'type' => ['required', Rule::in(EmployeeRecord::TYPES)],
            'severity' => ['required_if:type,disciplinary', 'prohibited_unless:type,disciplinary', 'nullable', Rule::in(EmployeeRecord::SEVERITIES)],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:10000'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $record = new EmployeeRecord($data);
        $record->forceFill(['employee_id' => $employee->id, 'created_by' => $request->user()->id])->save();

        return response()->json(['data' => $this->present($record->load('author:id,name'))], 201);
    }

    public function destroy(Employee $employee, EmployeeRecord $record): JsonResponse
    {
        abort_unless((int) $record->employee_id === $employee->id, 404);
        Gate::authorize('manageRecords', $employee);
        $record->delete();

        return response()->json(null, 204);
    }

    private function present(EmployeeRecord $r): array
    {
        return ['id' => $r->public_id, 'type' => $r->type, 'severity' => $r->severity, 'title' => $r->title, 'body' => $r->body,
            'occurred_on' => $r->occurred_on->toDateString(), 'author' => $r->author?->name, 'created_at' => $r->created_at?->toIso8601String()];
    }
}
