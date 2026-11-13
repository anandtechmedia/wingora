<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyContact extends TenantModel
{
    public const RELATIONSHIPS = ['spouse', 'partner', 'parent', 'child', 'sibling', 'relative', 'friend', 'other'];

    protected $fillable = ['name', 'relationship', 'phone', 'email', 'is_primary'];

    protected $hidden = ['id', 'tenant_id', 'employee_id'];

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'phone' => 'encrypted', 'email' => 'encrypted', 'is_primary' => 'boolean'];
    }

    public function auditEvent(string $action): string
    {
        return 'employee.emergency_contact_'.$action;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
