<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOpening extends TenantModel
{
    use RecruitmentAudited;

    public const STATUSES = ['draft', 'published', 'closed'];

    public const WORKPLACES = ['onsite', 'hybrid', 'remote'];

    protected $fillable = ['title', 'employment_type', 'workplace', 'openings', 'summary', 'description', 'requirements', 'closes_on'];

    protected $hidden = ['id', 'tenant_id', 'legal_entity_id', 'department_id', 'work_location_id', 'hiring_manager_id', 'created_by'];

    protected function casts(): array
    {
        return ['openings' => 'integer', 'published_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'closes_on' => LocalDate::class];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hiring_manager_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function isOpenForApplications(): bool
    {
        return $this->status === 'published' && ($this->closes_on === null || $this->closes_on->toDateString() >= now()->toDateString());
    }
}
