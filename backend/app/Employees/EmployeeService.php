<?php

namespace App\Employees;

use App\Billing\Entitlements;
use App\Country\RuleResolver;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and updates employees. Every reference is resolved through tenant-scoped queries, placement is
 * validated against country rules, and each change to job placement or status adds an employment history entry.
 */
class EmployeeService
{
    private const REFERENCES = [
        'department_id' => Department::class,
        'designation_id' => Designation::class,
        'manager_id' => Employee::class,
        'legal_entity_id' => LegalEntity::class,
        'work_location_id' => WorkLocation::class,
    ];

    public function __construct(private readonly RuleResolver $rules, private readonly Entitlements $entitlements) {}

    /** @param array<string, mixed> $input validated input with public ids */
    public function create(array $input, User $actor): Employee
    {
        return DB::transaction(function () use ($input, $actor) {
            if (($input['status'] ?? 'active') !== 'terminated') {
                $this->entitlements->assertEmployeeCapacity(Tenancy::current());
            }
            $employee = new Employee;
            $employee->fill(array_intersect_key($input, array_flip($employee->getFillable())));
            $employee->forceFill($this->employmentAttributes($input, null) + ['status' => $input['status'] ?? 'active']);
            $this->validatePlacement($employee, $input['hire_date'] ?? null);
            $employee->save();

            $this->recordHistory($employee, 'hire', $this->describe($employee, array_fill_keys(Employee::EMPLOYMENT_FIELDS, null)), $input['hire_date'] ?? now()->toDateString(), $input['note'] ?? null, $actor);

            return $employee;
        });
    }

    /** @param array<string, mixed> $input validated input with public ids */
    public function update(Employee $employee, array $input, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $input, $actor) {
            $employee->fill(array_intersect_key($input, array_flip($employee->getFillable())));
            $employee->forceFill($this->employmentAttributes($input, $employee));
            if (($input['status'] ?? null) === 'terminated' && $employee->termination_date === null) {
                $employee->termination_date = $input['effective_date'] ?? now()->toDateString();
            }
            if (isset($input['status']) && $input['status'] !== 'terminated' && $employee->getOriginal('status') === 'terminated') {
                $this->entitlements->assertEmployeeCapacity(Tenancy::current());
                $employee->termination_date = null;
            }

            $before = array_intersect_key($employee->getOriginal(), array_flip(Employee::EMPLOYMENT_FIELDS));
            $dirty = array_intersect(array_keys($employee->getDirty()), Employee::EMPLOYMENT_FIELDS);
            if (array_intersect($dirty, ['legal_entity_id', 'work_location_id', 'employment_type']) !== []) {
                $this->validatePlacement($employee, $input['effective_date'] ?? null);
            }
            $changes = $dirty === [] ? [] : $this->describe($employee, array_intersect_key($before, array_flip($dirty)));
            $employee->save();

            if ($changes !== []) {
                $this->recordHistory($employee, $this->changeType($dirty, $before, $employee), $changes, $input['effective_date'] ?? now()->toDateString(), $input['note'] ?? null, $actor);
            }

            return $employee;
        });
    }

    /** @return array<string, mixed> internal attributes for the employment fields present in $input */
    private function employmentAttributes(array $input, ?Employee $employee): array
    {
        $attributes = [];
        foreach (self::REFERENCES as $field => $class) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $attributes[$field] = $input[$field] === null ? null : $this->resolve($class, $input[$field], $field)->getKey();
        }
        foreach (['job_title', 'employment_type', 'status', 'hire_date', 'termination_date'] as $field) {
            if (array_key_exists($field, $input)) {
                $attributes[$field] = $input[$field];
            }
        }
        if (isset($attributes['manager_id'])) {
            $this->guardManager($employee, (int) $attributes['manager_id']);
        }

        return $attributes;
    }

    /** @param class-string<Model> $class */
    private function resolve(string $class, string $publicId, string $field): Model
    {
        return $class::query()->where('public_id', $publicId)->first()
            ?? throw ValidationException::withMessages([$field => __('The selected :attribute is invalid.', ['attribute' => str_replace('_', ' ', $field)])]);
    }

    private function guardManager(?Employee $employee, int $managerId): void
    {
        if ($employee === null) {
            return;
        }
        $seen = [];
        for ($id = $managerId; $id !== null && count($seen) < 100; $id = Employee::query()->whereKey($id)->value('manager_id')) {
            if ($id === $employee->id) {
                throw ValidationException::withMessages(['manager_id' => __('An employee cannot report to themselves or to someone who reports to them.')]);
            }
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
        }
    }

    private function validatePlacement(Employee $employee, ?string $date): void
    {
        $date ??= now()->toDateString();
        $entity = $employee->legal_entity_id ? LegalEntity::query()->find($employee->legal_entity_id) : null;
        $location = $employee->work_location_id ? WorkLocation::query()->find($employee->work_location_id) : null;

        $errors = match (true) {
            $location !== null && $entity === null => ['legal_entity_id' => __('A work location requires a legal entity.')],
            $entity !== null && $entity->status !== 'active' && $employee->isDirty('legal_entity_id') => ['legal_entity_id' => __('The legal entity is inactive.')],
            $location !== null && (int) $location->legal_entity_id !== (int) $entity->id => ['work_location_id' => __('The work location does not belong to the selected legal entity.')],
            $location !== null && $location->status !== 'active' && $employee->isDirty('work_location_id') => ['work_location_id' => __('The work location is inactive.')],
            $employee->employment_type !== null && $location === null => ['employment_type' => __('An employment type requires a work location.')],
            default => null,
        };
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if ($location !== null && $employee->employment_type !== null) {
            $allowed = $this->rules->employmentTypes($location, $date);
            if (! in_array($employee->employment_type, $allowed, true)) {
                throw ValidationException::withMessages(['employment_type' => $allowed === []
                    ? __('No employment types are approved for :region on :date.', ['region' => $location->subdivision_code, 'date' => $date])
                    : __('The employment type is not valid for this work location. Allowed: :types.', ['types' => implode(', ', $allowed)])]);
            }
        }
    }

    /**
     * Human-readable before/after values (names, never internal ids).
     *
     * @param  array<string, mixed>  $before
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function describe(Employee $employee, array $before): array
    {
        $label = function (string $field, mixed $value) {
            if ($value === null || $value === '') {
                return null;
            }
            if ($value instanceof Carbon) {
                return $value->toDateString();
            }

            return match ($field) {
                'department_id' => Department::query()->whereKey($value)->value('name'),
                'designation_id' => Designation::query()->whereKey($value)->value('name'),
                'legal_entity_id' => LegalEntity::query()->whereKey($value)->value('name'),
                'work_location_id' => WorkLocation::query()->whereKey($value)->value('name'),
                'manager_id' => Employee::query()->whereKey($value)->first()?->fullName(),
                'hire_date', 'termination_date' => substr((string) $value, 0, 10),
                default => $value,
            };
        };

        $changes = [];
        foreach ($before as $field => $old) {
            $from = $label($field, $old);
            $to = $label($field, $employee->getAttribute($field));
            if ($from !== $to) {
                $changes[str_replace('_id', '', $field)] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    private function changeType(array $dirty, array $before, Employee $employee): string
    {
        $was = $before['status'] ?? null;

        return match (true) {
            in_array('status', $dirty, true) && $employee->status === 'terminated' => 'termination',
            in_array('status', $dirty, true) && $was === 'terminated' => 'rehire',
            in_array('status', $dirty, true) => 'status_change',
            array_intersect($dirty, ['department_id', 'legal_entity_id', 'work_location_id']) !== [] => 'transfer',
            array_intersect($dirty, ['designation_id', 'job_title', 'employment_type']) !== [] => 'role_change',
            in_array('manager_id', $dirty, true) => 'manager_change',
            default => 'update',
        };
    }

    private function recordHistory(Employee $employee, string $type, array $changes, string $date, ?string $note, User $actor): void
    {
        $entry = new EmploymentHistory;
        $entry->forceFill([
            'employee_id' => $employee->id,
            'effective_date' => $date,
            'change_type' => $type,
            'changes' => $changes,
            'note' => $note,
            'recorded_by' => $actor->id,
        ])->save();
    }
}
