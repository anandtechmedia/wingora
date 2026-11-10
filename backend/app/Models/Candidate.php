<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Candidate extends TenantModel
{
    use RecruitmentAudited;

    public const SOURCES = ['careers', 'referral', 'agency', 'sourced', 'other'];

    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'location', 'linkedin_url', 'source'];

    protected $hidden = ['id', 'tenant_id', 'email_hash'];

    /** Contact details are personal data: they stay out of the audit trail. */
    protected array $auditRedacted = ['first_name', 'last_name', 'email', 'phone', 'location', 'linkedin_url'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'phone' => 'encrypted', 'on_hold' => 'boolean', 'anonymized_at' => 'immutable_datetime'];
    }

    public static function hashEmail(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
