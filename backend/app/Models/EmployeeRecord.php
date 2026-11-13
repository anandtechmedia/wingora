<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** HR notes and disciplinary records. Never visible to the employee they concern. */
class EmployeeRecord extends TenantModel
{
    use SoftDeletes;

    public const TYPES = ['note', 'disciplinary'];

    public const SEVERITIES = ['verbal_warning', 'written_warning', 'final_warning', 'suspension', 'other'];

    protected $fillable = ['type', 'severity', 'title', 'body', 'occurred_on'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'created_by'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'occurred_on' => 'date'];
    }

    public function auditEvent(string $action): string
    {
        return 'employee.hr_record_'.$action;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
