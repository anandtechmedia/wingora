<?php

namespace App\Http\Controllers\Api\V1;

use App\Country\RuleResolver;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenantReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\WorkLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Places an employee in a legal entity and work location. The employment type must be one approved for the
 * location's jurisdiction (country and subdivision rules) on the effective date; none approved fails closed.
 */
class EmployeeAssignmentController extends Controller
{
    use ResolvesTenantReferences;

    public function update(Request $request, Employee $employee, RuleResolver $resolver): EmployeeResource
    {
        Gate::authorize('assign', $employee);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'string', 'max:26'],
            'work_location_id' => ['required', 'string', 'max:26'],
            'employment_type' => ['required', 'string', 'max:40'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $entity = $this->owned(LegalEntity::class, $data['legal_entity_id'], 'legal_entity_id');
        $location = $this->owned(WorkLocation::class, $data['work_location_id'], 'work_location_id');
        $date = $data['effective_date'] ?? now()->toDateString();

        $errors = match (true) {
            $entity->status !== 'active' => ['legal_entity_id' => 'The legal entity is inactive.'],
            (int) $location->legal_entity_id !== (int) $entity->id => ['work_location_id' => 'The work location does not belong to the selected legal entity.'],
            $location->status !== 'active' => ['work_location_id' => 'The work location is inactive.'],
            default => null,
        };
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $allowed = $resolver->employmentTypes($location, $date);
        if (! in_array($data['employment_type'], $allowed, true)) {
            throw ValidationException::withMessages(['employment_type' => $allowed === []
                ? "No employment types are approved for {$location->subdivision_code} on {$date}."
                : 'The employment type is not valid for this work location. Allowed: '.implode(', ', $allowed).'.']);
        }

        $employee->forceFill([
            'legal_entity_id' => $entity->id,
            'work_location_id' => $location->id,
            'employment_type' => $data['employment_type'],
        ])->save();

        return new EmployeeResource($employee->load(['department', 'legalEntity', 'workLocation']));
    }
}
