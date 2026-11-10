<?php

namespace App\Models;

use App\Models\Concerns\RecruitmentAudited;
use Illuminate\Database\Eloquent\Collection;

class PipelineStage extends TenantModel
{
    use RecruitmentAudited;

    public const KINDS = ['active', 'hired', 'rejected'];

    /** Seeded once per tenant on first use; tenants rename, reorder or add active stages afterwards. */
    public const DEFAULTS = [
        ['key' => 'applied', 'name' => 'Applied', 'kind' => 'active'],
        ['key' => 'screening', 'name' => 'Screening', 'kind' => 'active'],
        ['key' => 'interview', 'name' => 'Interview', 'kind' => 'active'],
        ['key' => 'offer', 'name' => 'Offer', 'kind' => 'active'],
        ['key' => 'hired', 'name' => 'Hired', 'kind' => 'hired'],
        ['key' => 'rejected', 'name' => 'Rejected', 'kind' => 'rejected'],
    ];

    protected $fillable = ['key', 'name', 'kind', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return Collection<int, self> */
    public static function ensureDefaults(): Collection
    {
        if (! static::query()->exists()) {
            foreach (self::DEFAULTS as $i => $stage) {
                static::query()->create($stage + ['sort_order' => $i]);
            }
        }

        return static::query()->orderBy('sort_order')->orderBy('id')->get();
    }
}
