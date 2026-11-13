<?php

namespace App\Models;

use App\Models\Concerns\AssetsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAssignment extends TenantModel
{
    use AssetsAudited;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['assigned_on' => 'date', 'due_back_on' => 'date', 'returned_on' => 'date'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
