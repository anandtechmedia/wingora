<?php

namespace App\Models;

/** Weekly pattern: ISO weekday (1 = Monday … 7 = Sunday) → internal shift id, or null for a rest day. */
class WorkSchedule extends TenantModel
{
    protected $attributes = ['is_default' => false, 'status' => 'active'];

    protected $fillable = ['name', 'is_default', 'status'];

    protected function casts(): array
    {
        return ['days' => 'array', 'is_default' => 'boolean'];
    }

    public function shiftIdFor(int $isoWeekday): ?int
    {
        $id = $this->days[(string) $isoWeekday] ?? null;

        return $id === null ? null : (int) $id;
    }
}
