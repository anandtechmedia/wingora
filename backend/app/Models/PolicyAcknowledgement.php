<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAcknowledgement extends TenantModel
{
    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'policy_id', 'employee_id'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function auditEvent(string $action): string
    {
        return 'employee.policy_acknowledgement_'.$action;
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
