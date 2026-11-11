<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoredFile extends TenantModel
{
    use SoftDeletes;

    public const CATEGORIES = ['general', 'identity', 'contract', 'payroll', 'recruitment', 'onboarding', 'helpdesk', 'documents'];

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'uploaded_by', 'employee_id', 'disk', 'path'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
