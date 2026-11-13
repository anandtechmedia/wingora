<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunApproval extends Model
{
    use BelongsToTenant;

    protected $fillable = ['note'];

    protected $hidden = ['id', 'tenant_id', 'payroll_run_id', 'decided_by'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'review_cycle' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
