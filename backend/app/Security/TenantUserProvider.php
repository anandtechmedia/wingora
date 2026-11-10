<?php

namespace App\Security;

use App\Models\Concerns\TenantScope;
use Illuminate\Auth\EloquentUserProvider;

/** Authentication resolves users before any tenant context exists, so the tenant scope is lifted here only. */
class TenantUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
