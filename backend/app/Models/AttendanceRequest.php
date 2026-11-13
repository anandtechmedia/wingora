<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRequest extends TenantModel
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = ['reason'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'requested_by', 'decided_by'];

    protected function casts(): array
    {
        return ['work_date' => LocalDate::class, 'clock_in_at' => 'immutable_datetime', 'clock_out_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
