<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends TenantModel
{
    protected $attributes = ['type' => 'company', 'is_paid' => true];

    protected $fillable = ['date', 'name', 'type', 'is_paid'];

    protected $hidden = ['id', 'tenant_id', 'holiday_calendar_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_paid' => 'boolean'];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'holiday_calendar_id');
    }
}
