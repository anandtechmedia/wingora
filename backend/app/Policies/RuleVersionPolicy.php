<?php

namespace App\Policies;

use App\Models\RuleVersion;
use App\Models\User;
use App\Security\Permission as P;
use Illuminate\Auth\Access\Response;

class RuleVersionPolicy
{
    public function view(User $user, RuleVersion $version): bool
    {
        return $user->isPlatformStaff() && $user->hasPermission(P::PlatformCountryRulesManage);
    }

    public function update(User $user, RuleVersion $version): bool
    {
        return $this->view($user, $version);
    }

    public function submit(User $user, RuleVersion $version): bool
    {
        return $this->view($user, $version);
    }

    /** Maker-checker: nobody who created, edited or submitted a version may approve it. */
    public function approve(User $user, RuleVersion $version): Response
    {
        if (! ($user->isPlatformStaff() && $user->hasPermission(P::PlatformCountryRulesApprove))) {
            return Response::deny();
        }

        return $version->authoredBy($user)
            ? Response::deny('A rule version must be approved by someone other than its author or submitter.')
            : Response::allow();
    }

    public function reject(User $user, RuleVersion $version): bool
    {
        return $user->isPlatformStaff() && $user->hasPermission(P::PlatformCountryRulesApprove);
    }

    public function retire(User $user, RuleVersion $version): bool
    {
        return $this->reject($user, $version);
    }
}
