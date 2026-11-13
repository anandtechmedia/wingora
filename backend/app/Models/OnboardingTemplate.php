<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingTemplate extends TenantModel
{
    use RecruitmentAudited;

    protected $fillable = ['name', 'description', 'status'];

    protected $hidden = ['id', 'tenant_id', 'legal_entity_id'];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTemplateTask::class)->orderBy('sort_order')->orderBy('id');
    }
}
