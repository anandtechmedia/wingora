<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTemplateTask extends TenantModel
{
    use RecruitmentAudited;

    public const STAGES = ['pre_boarding', 'first_day', 'first_week', 'first_month'];

    public const ASSIGNEES = ['hr', 'manager', 'employee', 'user'];

    protected $fillable = ['stage', 'title', 'description', 'assignee', 'due_offset_days', 'document_key', 'document_name', 'sort_order'];

    protected $hidden = ['id', 'tenant_id', 'onboarding_template_id', 'assignee_user_id'];

    protected function casts(): array
    {
        return ['due_offset_days' => 'integer', 'sort_order' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'onboarding_template_id');
    }

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }
}
