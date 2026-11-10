<?php

namespace App\Models;

use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee's result within a run. `snapshot` freezes the names shown on the payslip at preparation time. */
class Payslip extends TenantModel
{
    use PayrollAudited;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'payroll_run_id', 'employee_id'];

    protected function casts(): array
    {
        return ['gross_minor' => 'integer', 'deductions_minor' => 'integer', 'net_minor' => 'integer', 'days_paid' => 'integer', 'period_days' => 'integer',
            'snapshot' => 'array', 'issues' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class)->orderByRaw("case kind when 'earning' then 0 else 1 end")->orderBy('id');
    }
}
