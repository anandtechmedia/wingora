<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Interview extends TenantModel
{
    use RecruitmentAudited;

    public const MODES = ['video', 'phone', 'onsite'];

    public const STATUSES = ['scheduled', 'completed', 'cancelled'];

    protected $fillable = ['title', 'mode', 'location', 'timezone'];

    protected $hidden = ['id', 'tenant_id', 'job_application_id', 'scheduled_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function panel(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'interview_panelists')->withPivot('tenant_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(InterviewFeedback::class);
    }

    public function hasPanelist(User $user): bool
    {
        return $this->panel()->where('users.id', $user->id)->exists();
    }
}
