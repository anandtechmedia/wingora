<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends TenantModel
{
    public const SOURCES = ['clock', 'manual', 'request'];

    protected $fillable = ['note'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'open_employee_id', 'shift_id', 'updated_by'];

    protected function casts(): array
    {
        return [
            'work_date' => LocalDate::class, 'clock_in_at' => 'immutable_datetime', 'clock_out_at' => 'immutable_datetime',
            'scheduled_start_at' => 'immutable_datetime', 'scheduled_end_at' => 'immutable_datetime',
            'break_minutes' => 'integer', 'worked_minutes' => 'integer', 'late_minutes' => 'integer', 'early_minutes' => 'integer', 'overtime_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
