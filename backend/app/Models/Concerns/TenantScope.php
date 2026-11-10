<?php

namespace App\Models\Concerns;

use App\Exceptions\TenantContextMissing;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Fails closed: querying tenant data without a tenant context throws. */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = Tenancy::id() ?? throw new TenantContextMissing('No tenant context for '.$model::class);

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
