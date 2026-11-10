<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;
use App\Security\Permission as P;

class PayrollRunPolicy
{
    use Concerns;

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::PayrollView);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $this->sameTenant($user, $run) && $user->hasPermission(P::PayrollView);
    }

    public function manage(User $user, ?PayrollRun $run = null): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::PayrollManage) && ($run === null || $this->sameTenant($user, $run));
    }

    public function export(User $user, PayrollRun $run): bool
    {
        return $this->view($user, $run) && $user->hasPermission(P::PayrollExport);
    }
}
