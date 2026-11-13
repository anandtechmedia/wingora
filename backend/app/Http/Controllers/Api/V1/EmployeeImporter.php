<?php

namespace App\Http\Controllers\Api\V1;

use App\Employees\EmployeeService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CSV import of new employees. `dry_run` validates every row and reports errors by line without writing.
 * A real import is all-or-nothing: any invalid row rejects the whole file.
 */
class EmployeeImporter extends Controller
{
    public const COLUMNS = ['employee_number', 'first_name', 'last_name', 'preferred_name', 'work_email', 'job_title', 'department', 'designation',
        'manager_employee_number', 'legal_entity_id', 'work_location_id', 'employment_type', 'status', 'hire_date'];

    public const MAX_ROWS = 1000;

    public function template(): JsonResponse
    {
        Gate::authorize('import', Employee::class);

        return response()->json(['data' => ['columns' => self::COLUMNS, 'max_rows' => self::MAX_ROWS]]);
    }

    public function __invoke(Request $request, EmployeeService $service): JsonResponse
    {
        Gate::authorize('import', Employee::class);
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv', 'max:2048'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);
        $dryRun = $request->boolean('dry_run');
        $rows = $this->read($request->file('file')->getRealPath());

        $departments = Department::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $designations = Designation::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $existing = Employee::query()->pluck('public_id', 'employee_number');
        $inFile = [];
        $errors = [];
        $prepared = [];

        foreach ($rows as $line => $row) {
            $v = Validator::make($row, [
                'employee_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'preferred_name' => ['nullable', 'string', 'max:100'],
                'work_email' => ['nullable', 'email:rfc', 'max:190'],
                'job_title' => ['nullable', 'string', 'max:160'],
                'status' => ['nullable', Rule::in(Employee::STATUSES)],
                'hire_date' => ['nullable', 'date_format:Y-m-d'],
                'employment_type' => ['nullable', 'string', 'max:40'],
            ]);
            $rowErrors = $v->errors()->all();
            $number = $row['employee_number'] ?? '';
            if ($number !== '' && ($existing->has($number) || isset($inFile[$number]))) {
                $rowErrors[] = __('Employee number :number already exists.', ['number' => $number]);
            }
            $inFile[$number] = true;
            foreach (['department' => $departments, 'designation' => $designations] as $field => $lookup) {
                if (($row[$field] ?? '') !== '' && ! $lookup->has(mb_strtolower($row[$field]))) {
                    $rowErrors[] = __('Unknown :field ":value".', ['field' => $field, 'value' => $row[$field]]);
                }
            }
            if ($rowErrors !== []) {
                $errors[] = ['line' => $line, 'employee_number' => $number ?: null, 'errors' => $rowErrors];

                continue;
            }
            $prepared[$line] = $row;
        }

        $summary = ['rows' => count($rows), 'valid' => count($prepared), 'invalid' => count($errors), 'dry_run' => $dryRun];
        if ($dryRun || $errors !== []) {
            if (! $dryRun) {
                AuditLog::record('employee.import_rejected', metadata: $summary, outcome: 'failure');
            }

            return response()->json(['data' => $summary + ['created' => 0, 'errors' => $errors]], $errors !== [] && ! $dryRun ? 422 : 200);
        }

        $created = DB::transaction(function () use ($prepared, $departments, $designations, $service, $request) {
            $byNumber = [];
            foreach ($prepared as $line => $row) {
                try {
                    $employee = $service->create(array_filter([
                        'employee_number' => $row['employee_number'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
                        'preferred_name' => $row['preferred_name'] ?: null, 'work_email' => $row['work_email'] ?: null, 'job_title' => $row['job_title'] ?: null,
                        'status' => $row['status'] ?: 'active', 'hire_date' => $row['hire_date'] ?: null, 'employment_type' => $row['employment_type'] ?: null,
                        'legal_entity_id' => $row['legal_entity_id'] ?: null, 'work_location_id' => $row['work_location_id'] ?: null,
                    ], fn ($v) => $v !== null), $request->user());
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['file' => __('Line :line: :message', ['line' => $line, 'message' => collect($e->errors())->flatten()->first()])]);
                }
                $employee->forceFill([
                    'department_id' => $departments[mb_strtolower($row['department'] ?? '')] ?? null,
                    'designation_id' => $designations[mb_strtolower($row['designation'] ?? '')] ?? null,
                ])->saveQuietly();
                $byNumber[$row['employee_number']] = [$employee, $row['manager_employee_number'] ?? '', $line];
            }
            foreach ($byNumber as [$employee, $managerNumber, $line]) {
                if ($managerNumber === '') {
                    continue;
                }
                $managerId = isset($byNumber[$managerNumber]) ? $byNumber[$managerNumber][0]->id : Employee::query()->where('employee_number', $managerNumber)->value('id');
                if (! $managerId || $managerId === $employee->id) {
                    throw ValidationException::withMessages(['file' => __('Line :line: unknown manager employee number.', ['line' => $line])]);
                }
                $employee->forceFill(['manager_id' => $managerId])->saveQuietly();
            }

            return count($byNumber);
        });

        AuditLog::record('employee.imported', metadata: $summary + ['created' => $created]);

        return response()->json(['data' => $summary + ['created' => $created, 'errors' => []]], 201);
    }

    /** @return array<int, array<string, string>> rows keyed by their line number in the file */
    private function read(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
        if (array_diff(['employee_number', 'first_name', 'last_name'], $header) !== [] || array_diff($header, self::COLUMNS) !== []) {
            throw ValidationException::withMessages(['file' => __('The header must use these columns: :columns.', ['columns' => implode(', ', self::COLUMNS)])]);
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($cells === [null] || array_filter($cells, fn ($c) => trim((string) $c) !== '') === []) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => __('A file may contain at most :max rows.', ['max' => self::MAX_ROWS])]);
            }
            $cells = array_pad(array_slice($cells, 0, count($header)), count($header), '');
            $rows[$line] = array_map(fn ($c) => trim((string) $c), array_combine($header, $cells)) + array_fill_keys(self::COLUMNS, '');
        }
        fclose($handle);

        return $rows;
    }
}
