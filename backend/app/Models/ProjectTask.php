<?php

namespace App\Models;

use App\Models\Concerns\ProjectsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTask extends TenantModel
{
    use ProjectsAudited;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'estimate_hours' => 'decimal:2', 'version' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }
}
