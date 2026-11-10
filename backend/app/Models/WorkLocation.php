<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An office or remote-work jurisdiction of a legal entity. Its subdivision decides which local rules apply. */
class WorkLocation extends TenantModel
{
    protected $attributes = ['status' => 'active', 'is_remote' => false];

    protected $fillable = ['name', 'code', 'country_code', 'subdivision_code', 'city', 'address_line1', 'postal_code', 'timezone', 'is_remote', 'status'];

    protected $hidden = ['id', 'tenant_id', 'legal_entity_id', 'holiday_calendar_id'];

    protected function casts(): array
    {
        return ['is_remote' => 'boolean'];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function holidayCalendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class);
    }
}
