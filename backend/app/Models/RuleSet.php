<?php

namespace App\Models;

use App\Country\RuleCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named rule family for one jurisdiction: a country (subdivision_code null) or a state/province/emirate.
 * Content lives in effective-dated, approved RuleVersions.
 */
class RuleSet extends Model
{
    use Auditable, HasPublicId;

    protected $fillable = ['country_code', 'subdivision_code', 'category', 'key', 'name', 'description'];

    protected $hidden = ['id', 'created_by'];

    protected function casts(): array
    {
        return ['category' => RuleCategory::class];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RuleVersion::class);
    }

    public function jurisdiction(): string
    {
        return $this->subdivision_code ?? $this->country_code;
    }
}
