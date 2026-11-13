<?php

namespace App\Models;

use App\Models\Concerns\PerformanceAudited;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A review period with its questions, due dates and rating scale. */
class PerformanceReviewCycle extends TenantModel
{
    use PerformanceAudited;

    public const STATUSES = ['draft', 'open', 'closed'];

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'created_by'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'self_due_on' => 'immutable_date', 'manager_due_on' => 'immutable_date', 'rating_scale' => 'integer', 'version' => 'integer'];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'review_cycle_id');
    }
}
