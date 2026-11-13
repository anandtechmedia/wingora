<?php

namespace App\Policies;

use App\Models\User;
use App\Security\Permission as P;

class UserPolicy
{
    use Concerns;

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::UsersView);
    }

    public function view(User $user, User $target): bool
    {
        return $user->is($target) || ($this->sameTenant($user, $target) && $user->hasPermission(P::UsersView));
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::UsersManage);
    }

    public function update(User $user, User $target): bool
    {
        return $this->sameTenant($user, $target) && $user->hasPermission(P::UsersManage) && ! $user->is($target);
    }

    public function assignRoles(User $user, User $target): bool
    {
        return $this->sameTenant($user, $target) && $user->hasPermission(P::RolesAssign) && ! $user->is($target);
    }
}
