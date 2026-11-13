<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends ReferenceModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_code', 'code');
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'country_languages', 'country_code', 'language_code', 'code', 'code')->withPivot('is_official');
    }

    public function subdivisions(): HasMany
    {
        return $this->hasMany(Subdivision::class, 'country_code', 'code');
    }

    public function timezones(): HasMany
    {
        return $this->hasMany(CountryTimezone::class, 'country_code', 'code');
    }

    public function locales(): HasMany
    {
        return $this->hasMany(LocaleFormat::class, 'country_code', 'code');
    }
}
