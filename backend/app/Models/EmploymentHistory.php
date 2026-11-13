<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry per change to an employee's job placement or status. Written only by EmployeeService. */
class EmploymentHistory extends TenantModel
{
    public const TYPES = ['hire', 'transfer', 'promotion', 'role_change', 'manager_change', 'status_change', 'termination', 'rehire', 'update'];

    protected $table = 'employment_history';

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'effective_date' => 'date'];
    }

    public function auditEvent(string $action): string
    {
        return 'employee.history_'.$action;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
