<?php

namespace App\Models\Concerns;

use App\Exceptions\TenantContextMissing;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model) {
            if (empty($model->tenant_id) && Tenancy::id()) {
                $model->tenant_id = Tenancy::id();
            }

            if (empty($model->tenant_id) && ! $model->allowsNullTenant()) {
                throw new TenantContextMissing('No tenant context for '.static::class);
            }

            if (Tenancy::id() && $model->tenant_id && (int) $model->tenant_id !== Tenancy::id()) {
                throw new LogicException('Cannot create a record for another tenant.');
            }
        });

        static::updating(function (self $model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id is immutable.');
            }
        });
    }

    /** Platform-level rows (no tenant) are only allowed where explicitly opted in. */
    public function allowsNullTenant(): bool
    {
        return false;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
