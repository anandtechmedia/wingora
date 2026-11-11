<?php

namespace App\Documents;

use App\Models\DocumentRequest;
use App\Models\User;
use App\Security\Permission as P;
use App\Time\Approvers;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees and decides which document requests. Requesters see their own. Managers see their team's requests that
 * have a manager step. HR (`documents.manage`) sees all; salary-bearing requests are decided and delivered only by
 * `documents.salary` holders, and only they and the requester can download the letter. Nobody decides their own request.
 */
final class DocumentAccess
{
    /** Uses the request's own salary flag, captured at submission, never the template's current one. */
    public static function finalPermission(DocumentRequest $r): P
    {
        return $r->includes_salary ? P::DocumentsSalary : P::DocumentsManage;
    }

    public static function any(User $user): bool
    {
        foreach ([P::DocumentsRequest, P::DocumentsApprove, P::DocumentsManage, P::DocumentsSalary, P::DocumentsConfigure] as $p) {
            if ($user->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    public static function requests(User $user): Builder
    {
        $query = DocumentRequest::query();
        if ($user->hasPermission(P::DocumentsManage)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('requester_id', $user->id);
            if ($user->hasPermission(P::DocumentsSalary)) {
                $q->orWhere('includes_salary', true);
            }
            if ($user->hasPermission(P::DocumentsApprove) && $user->employee) {
                $q->orWhere(fn (Builder $m) => $m->where('manager_step', true)
                    ->whereIn('employee_id', Approvers::visibleEmployees($user, P::DocumentsRequest, P::DocumentsApprove, P::DocumentsManage)
                        ->where('employees.id', '!=', $user->employee->id)->select('employees.id')));
            }
        });
    }

    public static function canDecide(User $user, DocumentRequest $r): bool
    {
        if ($r->requester_id === $user->id || $r->employee_id === $user->employee?->id) {
            return false;
        }

        return match ($r->status) {
            'pending_manager' => $user->hasPermission(P::DocumentsManage)
                || ($user->hasPermission(P::DocumentsApprove) && Approvers::isManagerOf($user, $r->employee)),
            // An approved request can still be rejected before delivery (e.g. its template changed).
            'pending_hr', 'approved' => $user->hasPermission(self::finalPermission($r)),
            default => false,
        };
    }

    public static function canDeliver(User $user, DocumentRequest $r): bool
    {
        return $r->status === 'approved' && $r->requester_id !== $user->id && $r->employee_id !== $user->employee?->id
            && $user->hasPermission(self::finalPermission($r));
    }

    public static function canDownload(User $user, DocumentRequest $r): bool
    {
        return $r->status === 'delivered' && $r->stored_file_id !== null
            && ($r->requester_id === $user->id || $user->hasPermission(self::finalPermission($r)));
    }
}
