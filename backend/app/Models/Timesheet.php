<?php

namespace App\Models;

use App\Models\Concerns\ProjectsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Timesheet extends TenantModel
{
    use ProjectsAudited;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'total_minutes' => 'integer', 'version' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }
}
