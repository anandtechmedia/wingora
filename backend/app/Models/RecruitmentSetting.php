<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;

class RecruitmentSetting extends TenantModel
{
    use RecruitmentAudited;

    public const DEFAULT_CONSENT = 'I agree that this organization may process the information and documents I submit to evaluate my application, and keep them for the retention period stated on this page. I can ask for them to be deleted at any time.';

    protected $fillable = ['careers_enabled', 'careers_slug', 'careers_headline', 'careers_intro', 'retention_days', 'consent_version', 'consent_text', 'ai_assist_enabled'];

    protected function casts(): array
    {
        return ['careers_enabled' => 'boolean', 'ai_assist_enabled' => 'boolean', 'retention_days' => 'integer'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['consent_text' => self::DEFAULT_CONSENT, 'consent_version' => 'v1', 'retention_days' => 365]);
    }
}
