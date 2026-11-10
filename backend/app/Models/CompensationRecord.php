<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Effective-dated pay history. `basis` says what `amount_minor` means: per year, per month or per pay period. */
class CompensationRecord extends TenantModel
{
    use PayrollAudited;

    public const BASES = ['annual', 'monthly', 'period'];

    public const FREQUENCIES = ['weekly', 'biweekly', 'semimonthly', 'monthly'];

    protected $fillable = ['amount_minor', 'basis', 'pay_frequency', 'effective_from', 'effective_to', 'reason'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'legal_entity_id', 'salary_structure_id', 'created_by'];

    /** @var list<string> */
    protected array $auditRedacted = ['amount_minor'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'effective_from' => LocalDate::class, 'effective_to' => LocalDate::class];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOverlapping(Builder $q, string $from, string $to): void
    {
        $q->where('effective_from', '<=', $to)->where(fn (Builder $w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $from));
    }
}
