<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Company leave type. Entitlements live in effective-dated LeavePolicy rows, never in code. */
class LeaveType extends TenantModel
{
    protected $attributes = ['is_paid' => true, 'allow_half_day' => true, 'requires_document' => false, 'approval_steps' => 1, 'status' => 'active'];

    protected $fillable = ['name', 'code', 'country_code', 'rule_key', 'is_paid', 'allow_half_day', 'requires_document', 'approval_steps', 'status'];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'allow_half_day' => 'boolean', 'requires_document' => 'boolean', 'approval_steps' => 'integer'];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class)->orderByDesc('effective_from');
    }
}
