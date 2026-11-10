<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Security\Permission as P;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformStaff() && $user->hasPermission(P::PlatformTenantsView);
    }

    public function create(User $user): bool
    {
        return $user->isPlatformStaff() && $user->hasPermission(P::PlatformTenantsManage);
    }

    /** Platform staff manage tenants; tenant users only ever see their own. */
    public function view(User $user, Tenant $tenant): bool
    {
        return ($user->isPlatformStaff() && $user->hasPermission(P::PlatformTenantsView))
            || ((int) $user->tenant_id === (int) $tenant->id && $user->hasPermission(P::OrganizationView));
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return ($user->isPlatformStaff() && $user->hasPermission(P::PlatformTenantsManage))
            || ((int) $user->tenant_id === (int) $tenant->id && $user->hasPermission(P::OrganizationManage));
    }

    public function updateSecurity(User $user, Tenant $tenant): bool
    {
        return (int) $user->tenant_id === (int) $tenant->id && $user->hasPermission(P::OrganizationSecurityManage);
    }

    public function changeStatus(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformStaff() && $user->hasPermission(P::PlatformTenantsManage);
    }
}
