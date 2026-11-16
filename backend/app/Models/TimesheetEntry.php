<?php

namespace App\Models;

use App\Models\Concerns\ProjectsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rows are replaced as a set when a timesheet is saved; the timesheet's own audit trail records the change in total. */
class TimesheetEntry extends TenantModel
{
    use ProjectsAudited;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'minutes' => 'integer'];
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }
}
