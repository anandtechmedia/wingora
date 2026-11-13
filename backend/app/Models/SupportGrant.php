<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Organization Owner's explicit, time-limited permission for Wingora support to view the organization. */
class SupportGrant extends TenantModel
{
    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'granted_by', 'revoked_by'];

    protected function casts(): array
    {
        return ['include_payroll' => 'boolean', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
