<?php

namespace App\Models;

use App\Models\Concerns\DocumentsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends TenantModel
{
    use DocumentsAudited;

    public const DELIVERIES = ['generated', 'upload'];

    public const FIELD_TYPES = ['text', 'textarea', 'date'];

    protected $fillable = ['name', 'description', 'delivery', 'body', 'fields', 'requires_manager', 'includes_salary', 'active', 'sort_order'];

    protected $hidden = ['id', 'tenant_id', 'legal_entity_id', 'created_by'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'requires_manager' => 'boolean', 'includes_salary' => 'boolean', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}
