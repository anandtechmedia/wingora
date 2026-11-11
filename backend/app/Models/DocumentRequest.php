<?php

namespace App\Models;

use App\Models\Concerns\DocumentsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequest extends TenantModel
{
    use DocumentsAudited;

    public const STATUSES = ['pending_manager', 'pending_hr', 'approved', 'rejected', 'delivered', 'cancelled'];

    public const PENDING = ['pending_manager', 'pending_hr'];

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'document_template_id', 'employee_id', 'requester_id', 'stored_file_id'];

    protected function casts(): array
    {
        return ['answers' => 'encrypted:array', 'rejection_reason' => 'encrypted', 'manager_step' => 'boolean', 'number' => 'integer', 'version' => 'integer',
            'manager_decided_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function managerDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_decided_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
