<?php

namespace App\Models;

use App\Models\Concerns\HelpdeskAudited;

class HelpdeskSetting extends TenantModel
{
    use HelpdeskAudited;

    /** Default due-by targets in hours. Companies set their own; no service level is implied. */
    public const DEFAULT_DUE_HOURS = ['low' => 120, 'normal' => 72, 'high' => 24, 'urgent' => 8];

    protected $fillable = ['due_hours'];

    protected function casts(): array
    {
        return ['due_hours' => 'array', 'next_number' => 'integer'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['due_hours' => self::DEFAULT_DUE_HOURS]);
    }
}
