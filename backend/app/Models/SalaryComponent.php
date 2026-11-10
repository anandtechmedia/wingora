<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An allowance or deduction template. `fixed` and `percent_of_base` are calculated; `manual` and `provider`
 * only describe a line that payroll staff or a provider must supply (used for tax and statutory items).
 */
class SalaryComponent extends TenantModel
{
    use PayrollAudited;

    public const KINDS = ['earning', 'deduction'];

    public const METHODS = ['fixed', 'percent_of_base', 'manual', 'provider'];

    protected $fillable = ['code', 'name', 'kind', 'method', 'amount_minor', 'rate_basis_points', 'rule_key', 'effective_from', 'effective_to', 'sort_order'];

    protected $hidden = ['id', 'tenant_id', 'salary_structure_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'rate_basis_points' => 'integer', 'sort_order' => 'integer', 'effective_from' => LocalDate::class, 'effective_to' => LocalDate::class];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function scopeEffectiveOn(Builder $q, string $date): void
    {
        $q->where('effective_from', '<=', $date)->where(fn (Builder $w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
