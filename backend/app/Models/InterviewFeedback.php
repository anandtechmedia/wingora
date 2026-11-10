<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewFeedback extends TenantModel
{
    use RecruitmentAudited;

    public const RECOMMENDATIONS = ['strong_yes', 'yes', 'no', 'strong_no'];

    protected $table = 'interview_feedback';

    protected $fillable = ['rating', 'recommendation', 'strengths', 'concerns', 'notes'];

    protected $hidden = ['id', 'tenant_id', 'interview_id', 'user_id'];

    protected array $auditRedacted = ['strengths', 'concerns', 'notes'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
