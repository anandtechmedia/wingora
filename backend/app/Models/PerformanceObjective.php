<?php

namespace App\Models;

use App\Models\Concerns\PerformanceAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An objective, or an OKR when it has measurable key results. `parent_id` aligns it to a higher objective. */
class PerformanceObjective extends TenantModel
{
    use PerformanceAudited;

    public const KINDS = ['objective', 'okr'];

    public const STATUSES = ['draft', 'active', 'completed', 'cancelled'];

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'parent_id', 'review_cycle_id', 'created_by'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'due_on' => 'immutable_date', 'completed_at' => 'immutable_datetime', 'progress' => 'integer', 'version' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'parent_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceReviewCycle::class, 'review_cycle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(PerformanceKeyResult::class, 'objective_id')->orderBy('sort_order')->orderBy('id');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(PerformanceCheckIn::class, 'objective_id');
    }
}
