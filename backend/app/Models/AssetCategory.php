<?php

namespace App\Models;

use App\Models\Concerns\AssetsAudited;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends TenantModel
{
    use AssetsAudited;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }
}
