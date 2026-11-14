<?php

namespace App\Models;

use App\Models\Concerns\PerformanceAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee's review in a cycle: self assessment, then the manager's assessment and rating, then acknowledgement. */
class PerformanceReview extends TenantModel
{
    use PerformanceAudited;

    public const STATUSES = ['pending_self', 'pending_manager', 'completed', 'acknowledged'];

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'review_cycle_id', 'employee_id', 'manager_employee_id', 'manager_submitted_by'];

    protected function casts(): array
    {
        return ['self_answers' => 'encrypted:array', 'manager_answers' => 'encrypted:array', 'rating' => 'integer', 'self_submitted_at' => 'immutable_datetime', 'manager_submitted_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceReviewCycle::class, 'review_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(PerformanceFeedback::class, 'review_id');
    }
}
