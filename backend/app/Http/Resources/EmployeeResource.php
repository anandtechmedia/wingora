<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'work_email' => $this->work_email,
            'job_title' => $this->job_title,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
            'legal_entity' => $this->whenLoaded('legalEntity', fn () => $this->legalEntity ? ['id' => $this->legalEntity->public_id, 'name' => $this->legalEntity->name, 'country_code' => $this->legalEntity->country_code] : null),
            'work_location' => $this->whenLoaded('workLocation', fn () => $this->workLocation ? ['id' => $this->workLocation->public_id, 'name' => $this->workLocation->name, 'subdivision_code' => $this->workLocation->subdivision_code, 'timezone' => $this->workLocation->timezone] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->public_id, 'name' => $this->department->name] : null),
        ];
    }
}
