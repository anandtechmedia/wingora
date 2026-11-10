<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AI output awaiting human review. Drafts are advisory text only and never change any other record. */
class AiDraft extends TenantModel
{
    use RecruitmentAudited;

    public const FEATURES = ['candidate_summary', 'document_organization'];

    protected $hidden = ['id', 'tenant_id', 'subject_type', 'subject_id', 'requested_by', 'reviewed_by'];

    protected array $auditRedacted = ['content'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime'];
    }

    public function auditEvent(string $action): string
    {
        return 'ai.draft_'.$action;
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
