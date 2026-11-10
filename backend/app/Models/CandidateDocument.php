<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateDocument extends TenantModel
{
    use RecruitmentAudited;

    public const KINDS = ['resume', 'cover_letter', 'portfolio', 'certificate', 'offer_letter', 'other'];

    protected $hidden = ['id', 'tenant_id', 'candidate_id', 'job_application_id', 'stored_file_id'];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
