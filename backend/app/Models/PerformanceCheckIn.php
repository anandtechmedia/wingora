<?php

namespace App\Models;

use App\Models\Concerns\PerformanceAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dated progress update on an objective or one of its key results. */
class PerformanceCheckIn extends TenantModel
{
    use PerformanceAudited;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'objective_id', 'key_result_id', 'created_by'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'progress' => 'integer'];
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'objective_id');
    }

    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(PerformanceKeyResult::class, 'key_result_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
