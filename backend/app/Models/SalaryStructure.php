<?php

namespace App\Models;

use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryStructure extends TenantModel
{
    use PayrollAudited;

    protected $fillable = ['name', 'code', 'status'];

    protected $hidden = ['id', 'tenant_id', 'legal_entity_id'];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(SalaryComponent::class)->orderBy('sort_order')->orderBy('id');
    }
}
