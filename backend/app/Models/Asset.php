<?php

namespace App\Models;

use App\Models\Concerns\AssetsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends TenantModel
{
    use AssetsAudited;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'warranty_ends_on' => 'date', 'purchase_cost' => 'decimal:2', 'version' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function currentEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'current_employee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(AssetMaintenanceRecord::class);
    }
}
