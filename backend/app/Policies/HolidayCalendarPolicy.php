<?php

namespace App\Policies;

use App\Models\HolidayCalendar;
use App\Models\User;
use App\Security\Permission as P;

class HolidayCalendarPolicy
{
    use Concerns;

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::OrganizationView);
    }

    public function view(User $user, HolidayCalendar $calendar): bool
    {
        return $this->sameTenant($user, $calendar) && $user->hasPermission(P::OrganizationView);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->hasPermission(P::HolidaysManage);
    }

    public function update(User $user, HolidayCalendar $calendar): bool
    {
        return $this->sameTenant($user, $calendar) && $user->hasPermission(P::HolidaysManage);
    }
}
