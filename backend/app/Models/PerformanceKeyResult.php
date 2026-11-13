<?php

namespace App\Models;

use App\Models\Concerns\PerformanceAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A measurable result of an OKR; progress is how far current_value has moved from start_value toward target_value. */
class PerformanceKeyResult extends TenantModel
{
    use PerformanceAudited;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'objective_id'];

    protected function casts(): array
    {
        return ['start_value' => 'decimal:2', 'target_value' => 'decimal:2', 'current_value' => 'decimal:2', 'weight' => 'integer', 'sort_order' => 'integer'];
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'objective_id');
    }
}
