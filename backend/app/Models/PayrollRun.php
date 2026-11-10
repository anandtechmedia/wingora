<?php

namespace App\Models;

use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** draft → in_review → approved → published. Rejection returns to draft; approved runs are immutable. */
class PayrollRun extends TenantModel
{
    use PayrollAudited;

    public const STATUSES = ['draft', 'in_review', 'approved', 'published'];

    protected $fillable = ['notes'];

    protected $hidden = ['id', 'tenant_id', 'payroll_period_id', 'legal_entity_id', 'regular_period_id', 'created_by', 'prepared_by', 'submitted_by', 'published_by'];

    protected function casts(): array
    {
        return ['approval_levels' => 'integer', 'current_level' => 'integer', 'review_cycle' => 'integer', 'employee_count' => 'integer',
            'gross_minor' => 'integer', 'deductions_minor' => 'integer', 'net_minor' => 'integer',
            'prepared_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PayrollRunApproval::class)->orderBy('review_cycle')->orderBy('level');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
