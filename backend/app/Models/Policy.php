<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A company policy employees acknowledge. Published versions are immutable; publish a new version instead. */
class Policy extends TenantModel
{
    public const STATUSES = ['draft', 'published', 'retired'];

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['title', 'version', 'summary', 'effective_date'];

    protected $hidden = ['id', 'tenant_id', 'file_id', 'created_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'published_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class);
    }
}
