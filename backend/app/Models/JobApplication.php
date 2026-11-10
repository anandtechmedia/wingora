<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends TenantModel
{
    use RecruitmentAudited;

    public const STATUSES = ['active', 'hired', 'rejected', 'withdrawn'];

    protected $fillable = ['cover_letter'];

    protected $hidden = ['id', 'tenant_id', 'job_opening_id', 'candidate_id', 'pipeline_stage_id', 'employee_id', 'consent_ip_hash'];

    protected array $auditRedacted = ['cover_letter'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'position' => 'integer', 'consented_at' => 'immutable_datetime', 'retain_until' => 'immutable_datetime', 'cover_letter' => 'encrypted'];
    }

    public function opening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class, 'job_opening_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(JobOffer::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }
}
