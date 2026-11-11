<?php

namespace App\Models;

class DeletionRequest extends TenantModel
{
    public const WAITING_DAYS = 30;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'requested_by', 'canceled_by'];

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'canceled_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
