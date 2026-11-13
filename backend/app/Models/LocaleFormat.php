<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CLDR-style date/number conventions for a language in a country (e.g. es-MX, fr-CA). */
class LocaleFormat extends ReferenceModel
{
    protected $table = 'locales';

    protected function casts(): array
    {
        return ['first_day_of_week' => 'integer'];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_code', 'code');
    }

    /** @return list<string> Locale codes whose language is active. */
    public static function activeCodes(): array
    {
        return once(fn () => static::query()
            ->whereHas('language', fn ($q) => $q->where('status', 'active'))
            ->orderBy('code')->pluck('code')->all());
    }
}
