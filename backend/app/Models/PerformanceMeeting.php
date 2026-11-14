<?php

namespace App\Models;

use App\Models\Concerns\PerformanceAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-to-one between a manager and a report. Private notes are visible only to the manager side. */
class PerformanceMeeting extends TenantModel
{
    use PerformanceAudited;

    public const STATUSES = ['scheduled', 'completed', 'cancelled'];

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'manager_employee_id', 'employee_id', 'created_by'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'duration_minutes' => 'integer', 'agenda' => 'encrypted', 'shared_notes' => 'encrypted', 'private_notes' => 'encrypted', 'action_items' => 'array', 'version' => 'integer'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
