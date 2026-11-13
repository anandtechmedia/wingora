<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected $attributes = ['status' => 'active'];

    public const STATUSES = ['active', 'on_leave', 'suspended', 'terminated'];

    /** Fields whose change is recorded in employment history. */
    public const EMPLOYMENT_FIELDS = ['department_id', 'designation_id', 'job_title', 'manager_id', 'legal_entity_id', 'work_location_id', 'employment_type', 'status', 'hire_date', 'termination_date'];

    protected $fillable = ['employee_number', 'first_name', 'last_name', 'preferred_name', 'work_email', 'job_title'];

    protected $hidden = [
        'id', 'tenant_id', 'user_id', 'department_id', 'designation_id', 'manager_id', 'legal_entity_id', 'work_location_id',
        'national_id', 'bank_account_number', 'date_of_birth', 'birthday_md', 'hire_md', 'personal_email', 'mobile_phone', 'home_address',
    ];

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'date_of_birth' => 'encrypted',
            'personal_email' => 'encrypted',
            'mobile_phone' => 'encrypted',
            'home_address' => 'encrypted:array',
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

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(EmploymentHistory::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(EmployeeRecord::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StoredFile::class);
    }

    public function policyAcknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class);
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

    public function compensationRecords(): HasMany
    {
        return $this->hasMany(CompensationRecord::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
