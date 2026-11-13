<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends TenantModel
{
    use PayrollAudited;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'payroll_calendar_id'];

    protected function casts(): array
    {
        return ['start_date' => LocalDate::class, 'end_date' => LocalDate::class, 'pay_date' => LocalDate::class];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(PayrollCalendar::class, 'payroll_calendar_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }
}
