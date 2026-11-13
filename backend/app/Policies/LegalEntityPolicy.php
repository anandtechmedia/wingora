<?php

namespace App\Policies;

use App\Models\LegalEntity;
use App\Models\User;
use App\Security\Permission as P;

class LegalEntityPolicy
{
    use Concerns;

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::OrganizationView);
    }

    public function view(User $user, LegalEntity $entity): bool
    {
        return $this->sameTenant($user, $entity) && $user->hasPermission(P::OrganizationView);
    }

    /** Registration and tax identifiers. */
    public function viewIdentifiers(User $user, LegalEntity $entity): bool
    {
        return $this->sameTenant($user, $entity)
            && ($user->hasPermission(P::LegalEntitiesManage) || $user->hasPermission(P::PayrollView));
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::LegalEntitiesManage);
    }

    public function update(User $user, LegalEntity $entity): bool
    {
        return $this->sameTenant($user, $entity) && $user->hasPermission(P::LegalEntitiesManage);
    }
}
