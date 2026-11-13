<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkLocation;
use App\Security\Permission as P;

class WorkLocationPolicy
{
    use Concerns;

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::OrganizationView);
    }

    public function view(User $user, WorkLocation $location): bool
    {
        return $this->sameTenant($user, $location) && $user->hasPermission(P::OrganizationView);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::LegalEntitiesManage);
    }

    public function update(User $user, WorkLocation $location): bool
    {
        return $this->sameTenant($user, $location) && $user->hasPermission(P::LegalEntitiesManage);
    }
}
