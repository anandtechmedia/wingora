<?php

namespace App\Models;

/** A shift in local wall-clock time. An end time at or before the start means the shift ends the next day. */
class Shift extends TenantModel
{
    protected $attributes = ['break_minutes' => 0, 'late_grace_minutes' => 0, 'early_grace_minutes' => 0, 'status' => 'active'];

    protected $fillable = ['name', 'code', 'start_time', 'end_time', 'break_minutes', 'late_grace_minutes', 'early_grace_minutes', 'overtime_after_minutes', 'status'];

    protected function casts(): array
    {
        return ['break_minutes' => 'integer', 'late_grace_minutes' => 'integer', 'early_grace_minutes' => 'integer', 'overtime_after_minutes' => 'integer'];
    }
}
