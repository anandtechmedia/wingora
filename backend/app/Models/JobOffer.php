<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOffer extends TenantModel
{
    use RecruitmentAudited;

    public const STATUSES = ['draft', 'approved', 'sent', 'accepted', 'declined', 'withdrawn'];

    public const FREQUENCIES = ['annual', 'monthly', 'hourly'];

    /** Allowed status moves. Only a person's explicit action moves an offer; nothing changes status automatically. */
    public const TRANSITIONS = [
        'draft' => ['approved', 'withdrawn'],
        'approved' => ['sent', 'withdrawn'],
        'sent' => ['accepted', 'declined', 'withdrawn'],
        'accepted' => [],
        'declined' => [],
        'withdrawn' => [],
    ];

    protected $fillable = ['job_title', 'pay_frequency', 'start_date', 'expires_on', 'notes'];

    protected $hidden = ['id', 'tenant_id', 'job_application_id', 'letter_file_id', 'created_by', 'approved_by'];

    protected array $auditRedacted = ['base_salary_minor', 'notes'];

    protected function casts(): array
    {
        return ['base_salary_minor' => 'integer', 'start_date' => LocalDate::class, 'expires_on' => LocalDate::class, 'approved_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime', 'responded_at' => 'immutable_datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'letter_file_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
