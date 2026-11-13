<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestApproval extends TenantModel
{
    public $timestamps = false;

    protected $fillable = ['note'];

    protected $hidden = ['id', 'tenant_id', 'leave_request_id', 'decided_by'];

    protected function casts(): array
    {
        return ['step' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
