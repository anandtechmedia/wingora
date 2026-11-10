<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingPlan extends TenantModel
{
    use RecruitmentAudited;

    public const STATUSES = ['in_progress', 'completed', 'cancelled'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'onboarding_template_id', 'job_application_id', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => LocalDate::class, 'completed_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'onboarding_template_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class)->orderBy('due_on')->orderBy('sort_order')->orderBy('id');
    }
}
