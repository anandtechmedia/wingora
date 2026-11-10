<?php

namespace App\Models;

use App\Models\Concerns\TenantScope;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class PersonalAccessToken extends SanctumToken
{
    /** Token lookup happens before a tenant context exists; the tenant is derived from the user afterwards. */
    public function tokenable()
    {
        return $this->morphTo('tokenable')->withoutGlobalScope(TenantScope::class);
    }
}
