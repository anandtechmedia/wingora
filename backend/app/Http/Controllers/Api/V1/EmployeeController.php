<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvents;
use App\Employees\EmployeeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\WorkLocation;
use App\Security\EmployeeAccess;
use App\Support\Csv;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    private const RELATIONS = ['department', 'designation', 'manager', 'legalEntity', 'workLocation'];

    private const SORTS = ['name' => ['last_name', 'first_name'], 'employee_number' => ['employee_number'], 'hire_date' => ['hire_date']];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);
        $data = $request->validate($this->filterRules() + [
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'sort' => ['sometimes', Rule::in(array_merge(array_keys(self::SORTS), array_map(fn ($s) => '-'.$s, array_keys(self::SORTS))))],
        ]);

        $sort = $data['sort'] ?? 'name';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query = $this->filtered($request, $data)->with(self::RELATIONS);
        foreach (self::SORTS[ltrim($sort, '-')] as $column) {
            $query->orderBy($column, $direction);
        }

        return EmployeeResource::collection($query->orderBy('id')->paginate((int) ($data['per_page'] ?? 25)));
    }

    public function store(Request $request, EmployeeService $service): JsonResponse
    {
        Gate::authorize('create', Employee::class);
        $data = $request->validate($this->writeRules(null) + ['note' => ['nullable', 'string', 'max:500']]);
        $employee = $service->create($data, $request->user());

        return $this->present($employee)->response()->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return $this->present($employee);
    }

    public function update(Request $request, Employee $employee, EmployeeService $service): EmployeeResource
    {
        Gate::authorize('update', $employee);
        $data = $request->validate($this->writeRules($employee) + [
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->present($service->update($employee, $data, $request->user()));
    }

    public function history(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);
        $page = $employee->history()->with('recorder:id,name')->orderByDesc('effective_date')->orderByDesc('id')
            ->paginate((int) ($request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']])['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($h) => [
                'id' => $h->public_id,
                'effective_date' => $h->effective_date->toDateString(),
                'change_type' => $h->change_type,
                'changes' => $h->changes,
                'note' => $h->note,
                'recorded_by' => $h->recorder?->name,
                'recorded_at' => $h->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function personal(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('viewPersonal', $employee);
        if ($request->user()->employee?->id !== $employee->id) {
            AuditLog::record('employee.personal_viewed', $employee);
        }

        return response()->json(['data' => $this->personalData($employee)]);
    }

    public function updatePersonal(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('updatePersonal', $employee);
        $data = $request->validate([
            'preferred_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'nationality' => ['sometimes', 'nullable', 'string', 'size:2', 'exists:countries,code'],
            'personal_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'mobile_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ().-]{6,30}$/'],
            'home_address' => ['sometimes', 'nullable', 'array:line1,line2,city,region,postal_code,country_code'],
            'home_address.line1' => ['required_with:home_address', 'string', 'max:190'],
            'home_address.line2' => ['nullable', 'string', 'max:190'],
            'home_address.city' => ['required_with:home_address', 'string', 'max:100'],
            'home_address.region' => ['nullable', 'string', 'max:100'],
            'home_address.postal_code' => ['nullable', 'string', 'max:20'],
            'home_address.country_code' => ['required_with:home_address', 'string', 'size:2', 'exists:countries,code'],
        ]);
        $employee->forceFill($data)->save();

        return response()->json(['data' => $this->personalData($employee)]);
    }

    public function sensitive(Employee $employee): JsonResponse
    {
        Gate::authorize('viewSensitive', $employee);
        AuditLog::record('employee.sensitive_viewed', $employee);

        return response()->json(['data' => $this->sensitiveData($employee)]);
    }

    public function updateSensitive(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('updateSensitive', $employee);
        $data = $request->validate([
            'national_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
        ]);
        $employee->forceFill($data)->save();

        return response()->json(['data' => $this->sensitiveData($employee)]);
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

    /** Directory and employment columns only; never personal, sensitive or HR-record data. */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('export', Employee::class);
        $data = $request->validate($this->filterRules());
        $query = $this->filtered($request, $data)->with(self::RELATIONS)->orderBy('last_name')->orderBy('first_name')->orderBy('id');
        AuditLog::record(AuditEvents::DATA_EXPORTED, metadata: ['resource' => 'employees', 'filters' => array_filter($data), 'rows' => (clone $query)->count()]);

        $tenant = Tenancy::current();

        // The body streams after middleware has cleared the tenant context, so restore it here.
        return response()->streamDownload(fn () => Tenancy::run($tenant, function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, EmployeeImporter::COLUMNS, escape: '');
            $query->chunk(500, function ($employees) use ($out) {
                foreach ($employees as $e) {
                    fputcsv($out, array_map(Csv::cell(...), [
                        $e->employee_number, $e->first_name, $e->last_name, $e->preferred_name, $e->work_email, $e->job_title,
                        $e->department?->name, $e->designation?->name, $e->manager?->employee_number, $e->legalEntity?->public_id,
                        $e->workLocation?->public_id, $e->employment_type, $e->status, $e->hire_date?->toDateString(),
                    ]), escape: '');
                }
            });
            fclose($out);
        }), 'employees-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function present(Employee $employee): EmployeeResource
    {
        $resource = new EmployeeResource($employee->load(self::RELATIONS));
        $resource->withAbilities = true;

        return $resource;
    }

    private function personalData(Employee $employee): array
    {
        return [
            'preferred_name' => $employee->preferred_name,
            'nationality' => $employee->nationality,
            'personal_email' => $employee->personal_email,
            'mobile_phone' => $employee->mobile_phone,
            'home_address' => $employee->home_address,
        ];
    }

    private function sensitiveData(Employee $employee): array
    {
        return [
            'id' => $employee->public_id,
            'national_id' => $employee->national_id,
            'bank_account_number' => $employee->bank_account_number,
            'date_of_birth' => $employee->date_of_birth,
        ];
    }

    private function filterRules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(Employee::STATUSES)],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'department' => ['sometimes', 'nullable', 'string', 'max:26'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:26'],
            'legal_entity' => ['sometimes', 'nullable', 'string', 'max:26'],
            'work_location' => ['sometimes', 'nullable', 'string', 'max:26'],
            'manager' => ['sometimes', 'nullable', 'string', 'max:26'],
        ];
    }

    /** Filters by public id resolve through tenant-scoped lookups; an unknown or foreign id matches nothing. */
    private function filtered(Request $request, array $data): Builder
    {
        $query = EmployeeAccess::scopeEmployees(Employee::query(), $request->user());
        $refs = ['department' => [Department::class, 'department_id'], 'designation' => [Designation::class, 'designation_id'],
            'legal_entity' => [LegalEntity::class, 'legal_entity_id'], 'work_location' => [WorkLocation::class, 'work_location_id'], 'manager' => [Employee::class, 'manager_id']];
        foreach ($refs as $key => [$class, $column]) {
            if (! empty($data[$key])) {
                $query->where($column, $class::query()->where('public_id', $data[$key])->value('id') ?? 0);
            }
        }
        if (! empty($data['country'])) {
            $query->whereIn('legal_entity_id', LegalEntity::query()->where('country_code', strtoupper($data['country']))->select('id'));
        }
        if (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)
                ->orWhere('preferred_name', 'like', $term)->orWhere('employee_number', 'like', $term)->orWhere('work_email', 'like', $term));
        }

        return $query->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['employment_type'] ?? null, fn ($q, $v) => $q->where('employment_type', $v));
    }

    private function writeRules(?Employee $employee): array
    {
        $creating = $employee === null;
        $req = $creating ? 'required' : 'sometimes';

        return [
            'employee_number' => [$req, 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-\/]+$/', Rule::unique('employees')->where('tenant_id', $employee?->tenant_id ?? request()->user()->tenant_id)->ignore($employee?->id)],
            'first_name' => [$req, 'string', 'max:100'],
            'last_name' => [$req, 'string', 'max:100'],
            'preferred_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'work_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(Employee::STATUSES)],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hire_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'termination_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:hire_date'],
            'department_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'designation_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'manager_id' => ['sometimes', 'nullable', 'string', 'max:26', $employee ? Rule::notIn([$employee->public_id]) : 'string'],
            'legal_entity_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'work_location_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ];
    }
}
