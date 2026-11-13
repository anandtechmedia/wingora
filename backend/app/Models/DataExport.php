<?php

namespace App\Models;

class DataExport extends TenantModel
{
    public const RETENTION_DAYS = 7;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'requested_by', 'disk', 'path'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'expires_at' => 'datetime', 'size' => 'integer'];
    }
}
