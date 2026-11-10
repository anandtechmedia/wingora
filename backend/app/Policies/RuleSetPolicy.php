<?php

namespace App\Policies;

use App\Models\RuleSet;
use App\Models\User;
use App\Security\Permission as P;

/** Country rules are platform-managed reference content; tenant users can only read approved, effective rules. */
class RuleSetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformStaff() && $user->hasPermission(P::PlatformCountryRulesManage);
    }

    public function view(User $user, RuleSet $set): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, RuleSet $set): bool
    {
        return $this->viewAny($user);
    }
}
