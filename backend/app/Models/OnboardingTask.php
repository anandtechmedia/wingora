<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends TenantModel
{
    use RecruitmentAudited;

    public const STATUSES = ['pending', 'submitted', 'completed', 'returned', 'skipped'];

    public const DONE = ['completed', 'skipped'];

    protected $hidden = ['id', 'tenant_id', 'onboarding_plan_id', 'assignee_user_id', 'document_file_id', 'completed_by'];

    protected function casts(): array
    {
        return ['due_on' => LocalDate::class, 'last_reminded_on' => LocalDate::class, 'completed_at' => 'immutable_datetime', 'sort_order' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(OnboardingPlan::class, 'onboarding_plan_id');
    }

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'document_file_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
