<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends TenantModel
{
    protected $fillable = ['effective_from', 'effective_to'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'work_schedule_id', 'created_by'];

    protected function casts(): array
    {
        return ['effective_from' => LocalDate::class, 'effective_to' => LocalDate::class];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
