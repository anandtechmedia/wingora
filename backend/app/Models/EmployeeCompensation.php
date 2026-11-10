<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCompensation extends TenantModel
{
    protected $table = 'employee_compensations';

    protected $fillable = ['currency', 'base_salary', 'pay_frequency', 'effective_date'];

    protected $hidden = ['id', 'tenant_id', 'employee_id'];

    /** @var list<string> */
    protected array $auditRedacted = ['base_salary'];

    protected function casts(): array
    {
        return ['base_salary' => 'decimal:2', 'effective_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
