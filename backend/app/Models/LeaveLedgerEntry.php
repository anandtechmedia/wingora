<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only balance movement. Balances are sums of entries; nothing is ever edited in place. */
class LeaveLedgerEntry extends TenantModel
{
    public const TYPES = ['allocation', 'accrual', 'carry_forward', 'carry_out', 'lapse', 'deduction', 'reversal', 'adjustment'];

    protected $fillable = ['note'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'leave_type_id', 'leave_request_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'period_year' => 'integer', 'effective_on' => LocalDate::class, 'expires_on' => LocalDate::class];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
