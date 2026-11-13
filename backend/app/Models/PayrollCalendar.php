<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollCalendar extends TenantModel
{
    use PayrollAudited;

    protected $fillable = ['name', 'pay_frequency', 'first_period_start', 'pay_date_offset_days', 'approval_levels', 'provider', 'status'];

    protected $hidden = ['id', 'tenant_id', 'legal_entity_id'];

    protected function casts(): array
    {
        return ['first_period_start' => LocalDate::class, 'pay_date_offset_days' => 'integer', 'approval_levels' => 'integer'];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(PayrollPeriod::class)->orderBy('start_date');
    }
}
