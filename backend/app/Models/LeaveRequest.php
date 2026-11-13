<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends TenantModel
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public const HALF_DAYS = ['first_half', 'second_half'];

    protected $fillable = ['reason'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'leave_type_id', 'document_id', 'requested_by'];

    protected function casts(): array
    {
        return ['start_date' => LocalDate::class, 'end_date' => LocalDate::class, 'days' => 'float', 'current_step' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'document_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveRequestApproval::class)->orderBy('step');
    }
}
