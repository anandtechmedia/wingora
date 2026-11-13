<?php

namespace App\Models;

use App\Models\Concerns\AssetsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetMaintenanceRecord extends TenantModel
{
    use AssetsAudited;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['scheduled_on' => 'date', 'completed_on' => 'date', 'cost' => 'decimal:2', 'version' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
