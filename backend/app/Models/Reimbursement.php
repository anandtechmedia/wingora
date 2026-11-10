<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Models\Concerns\PayrollAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reimbursement extends TenantModel
{
    use PayrollAudited;

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'paid'];

    public const CATEGORIES = ['travel', 'meals', 'lodging', 'equipment', 'training', 'communication', 'other'];

    protected $fillable = ['category', 'description', 'expense_date'];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'receipt_file_id', 'submitted_by', 'decided_by', 'paid_run_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'expense_date' => LocalDate::class, 'decided_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'receipt_file_id');
    }

    public function paidRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'paid_run_id');
    }
}
