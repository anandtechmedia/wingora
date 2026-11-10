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
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->public_id, 'name' => $this->department->name] : null),
        ];
    }
}
