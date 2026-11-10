<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Company holiday calendar; optionally layered on top of approved statutory holidays for its jurisdiction. */
class HolidayCalendar extends TenantModel
{
    protected $attributes = ['include_statutory' => true];

    protected $fillable = ['name', 'country_code', 'subdivision_code', 'include_statutory'];

    protected function casts(): array
    {
        return ['include_statutory' => 'boolean'];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class)->orderBy('date');
    }
}
