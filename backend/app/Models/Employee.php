<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['employee_number', 'first_name', 'last_name', 'work_email', 'job_title'];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'department_id', 'manager_id', 'legal_entity_id', 'work_location_id', 'national_id', 'bank_account_number', 'date_of_birth', 'birthday_md', 'hire_md'];

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'date_of_birth' => 'encrypted',
            'hire_date' => 'date',
            'termination_date' => 'date',
        ];
    }

    /** Keeps the year-less lookup columns in sync with their source dates. */
    protected static function booted(): void
    {
        static::saving(function (self $employee) {
            if ($employee->isDirty('date_of_birth')) {
                $employee->birthday_md = self::monthDay($employee->date_of_birth);
            }
            if ($employee->isDirty('hire_date')) {
                $employee->hire_md = $employee->hire_date?->format('m-d');
            }
        });
    }

    private static function monthDay(?string $date): ?string
    {
        return $date !== null && preg_match('/^\d{4}-(\d{2})-(\d{2})/', $date, $m) ? $m[1].'-'.$m[2] : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function compensation(): HasOne
    {
        return $this->hasOne(EmployeeCompensation::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
