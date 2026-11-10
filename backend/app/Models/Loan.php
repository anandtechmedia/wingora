<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An employee advance repaid by fixed instalments. No interest is calculated. */
class Loan extends TenantModel
{
    use PayrollAudited;

    protected $fillable = ['principal_minor', 'installment_minor', 'first_deduction_on', 'reason'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'created_by'];

    /** @var list<string> */
    protected array $auditRedacted = ['principal_minor', 'installment_minor'];

    protected function casts(): array
    {
        return ['principal_minor' => 'integer', 'installment_minor' => 'integer', 'first_deduction_on' => LocalDate::class];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function outstanding(): int
    {
        return $this->principal_minor - (int) $this->repayments()->sum('amount_minor');
    }
}
