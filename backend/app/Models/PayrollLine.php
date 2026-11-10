<?php

namespace App\Models;

use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** `source` is calculated (by Wingora), manual (entered by payroll staff, with a reason) or provider (imported, with a reference). */
class PayrollLine extends TenantModel
{
    use PayrollAudited;

    public const SOURCES = ['calculated', 'manual', 'provider'];

    protected $fillable = ['code', 'name', 'kind', 'amount_minor', 'reason'];

    protected $hidden = ['id', 'tenant_id', 'payslip_id', 'payroll_run_id', 'loan_id', 'reimbursement_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'basis' => 'array'];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
