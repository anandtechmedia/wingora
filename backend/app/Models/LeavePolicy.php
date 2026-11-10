<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeavePolicy extends TenantModel
{
    public const ACCRUALS = ['annual', 'monthly', 'none'];

    protected $attributes = ['accrual' => 'annual', 'prorate_new_joiners' => true, 'carry_forward_max' => 0, 'allow_negative' => false, 'min_notice_days' => 0];

    protected $fillable = ['effective_from', 'effective_to', 'annual_entitlement', 'accrual', 'prorate_new_joiners', 'carry_forward_max',
        'carry_forward_expiry_months', 'allow_negative', 'max_consecutive_days', 'min_notice_days'];

    protected $hidden = ['id', 'tenant_id', 'leave_type_id', 'legal_entity_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => LocalDate::class, 'effective_to' => LocalDate::class, 'annual_entitlement' => 'float', 'carry_forward_max' => 'float',
            'prorate_new_joiners' => 'boolean', 'allow_negative' => 'boolean', 'carry_forward_expiry_months' => 'integer',
            'max_consecutive_days' => 'integer', 'min_notice_days' => 'integer',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
