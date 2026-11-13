<?php

namespace App\Models;

use App\Models\Concerns\PerformanceAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 360-degree feedback on a review. Anonymous feedback never reveals the reviewer to the subject. */
class PerformanceFeedback extends TenantModel
{
    use PerformanceAudited;

    public const RELATIONSHIPS = ['peer', 'direct_report', 'manager', 'other'];

    public const STATUSES = ['requested', 'submitted', 'declined'];

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'review_id', 'reviewer_id', 'requested_by'];

    protected function casts(): array
    {
        return ['answers' => 'encrypted:array', 'anonymous' => 'boolean', 'submitted_at' => 'immutable_datetime'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'review_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
