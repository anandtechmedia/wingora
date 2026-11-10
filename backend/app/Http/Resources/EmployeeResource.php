<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Directory and employment fields only. Personal, sensitive and HR-record data have their own
 * endpoints and policies. `abilities` tells the client which of those the caller may use.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    public bool $withAbilities = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'preferred_name' => $this->preferred_name,
            'work_email' => $this->work_email,
            'job_title' => $this->job_title,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
            'hire_date' => $this->hire_date?->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'legal_entity' => $this->whenLoaded('legalEntity', fn () => $this->legalEntity ? ['id' => $this->legalEntity->public_id, 'name' => $this->legalEntity->name, 'country_code' => $this->legalEntity->country_code] : null),
            'work_location' => $this->whenLoaded('workLocation', fn () => $this->workLocation ? ['id' => $this->workLocation->public_id, 'name' => $this->workLocation->name, 'subdivision_code' => $this->workLocation->subdivision_code, 'timezone' => $this->workLocation->timezone] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->public_id, 'name' => $this->department->name] : null),
            'designation' => $this->whenLoaded('designation', fn () => $this->designation ? ['id' => $this->designation->public_id, 'name' => $this->designation->name] : null),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? ['id' => $this->manager->public_id, 'name' => $this->manager->fullName(), 'job_title' => $this->manager->job_title] : null),
            'abilities' => $this->when($this->withAbilities, fn () => [
                'update' => Gate::allows('update', $this->resource),
                'view_personal' => Gate::allows('viewPersonal', $this->resource),
                'update_personal' => Gate::allows('updatePersonal', $this->resource),
                'view_sensitive' => Gate::allows('viewSensitive', $this->resource),
                'update_sensitive' => Gate::allows('updateSensitive', $this->resource),
                'view_records' => Gate::allows('viewRecords', $this->resource),
                'manage_records' => Gate::allows('manageRecords', $this->resource),
            ]),
        ];
    }
}
