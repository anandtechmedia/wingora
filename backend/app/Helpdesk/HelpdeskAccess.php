<?php

namespace App\Helpdesk;

use App\Models\HelpdeskCategory;
use App\Models\HelpdeskTicket;
use App\Models\User;
use App\Security\Permission as P;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees which tickets. Requesters always see their own. Agents and helpdesk managers see the queue,
 * except restricted categories (e.g. harassment), which only their listed agents can open.
 */
final class HelpdeskAccess
{
    public static function isStaff(User $user): bool
    {
        return $user->hasPermission(P::HelpdeskAgent) || $user->hasPermission(P::HelpdeskManage);
    }

    /** Categories this staff member may work. */
    public static function workableCategories(User $user): Builder
    {
        return HelpdeskCategory::query()->where(fn ($q) => $q->where('restricted', false)
            ->orWhereHas('agents', fn ($a) => $a->where('users.id', $user->id)));
    }

    public static function tickets(User $user): Builder
    {
        $query = HelpdeskTicket::query();
        if (! self::isStaff($user)) {
            return $user->hasPermission(P::HelpdeskSubmit) ? $query->where('requester_id', $user->id) : $query->whereRaw('1 = 0');
        }

        return $query->where(fn ($q) => $q->where('requester_id', $user->id)
            ->orWhereIn('helpdesk_category_id', self::workableCategories($user)->select('id')));
    }

    /** True when the user handles this ticket as staff (not merely as its requester). */
    public static function works(User $user, HelpdeskTicket $ticket): bool
    {
        return self::isStaff($user) && self::workableCategories($user)->whereKey($ticket->helpdesk_category_id)->exists();
    }

    public static function canAssignTo(User $assignee, HelpdeskTicket $ticket): bool
    {
        return $assignee->tenant_id === $ticket->tenant_id && self::works($assignee, $ticket);
    }
}
