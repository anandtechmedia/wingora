<?php

namespace App\Recruitment;

use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\User;
use App\Security\Permission as P;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees which openings and applications, enforced on every query:
 * - recruitment.manage: every opening and application in the tenant.
 * - recruitment.view: openings they are hiring manager for, or in a department they manage.
 * - recruitment.interview: only applications with an interview they sit on.
 */
final class RecruitmentAccess
{
    public static function manages(User $user): bool
    {
        return $user->hasPermission(P::RecruitmentManage);
    }

    public static function openings(User $user): Builder
    {
        $query = JobOpening::query();
        if (self::manages($user)) {
            return $query;
        }
        if (! $user->hasPermission(P::RecruitmentView) || ! $user->employee) {
            return $query->whereRaw('1 = 0');
        }
        $me = $user->employee->id;

        return $query->where(fn ($q) => $q->where('hiring_manager_id', $me)
            ->orWhereIn('department_id', fn ($s) => $s->select('id')->from('departments')->where('manager_employee_id', $me)->where('tenant_id', $user->tenant_id)));
    }

    public static function applications(User $user): Builder
    {
        $query = JobApplication::query();
        if (self::manages($user)) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereIn('job_opening_id', self::openings($user)->select('job_openings.id'))
            ->orWhereIn('id', Interview::query()->whereHas('panel', fn ($p) => $p->where('users.id', $user->id))->select('job_application_id')));
    }

    public static function canSee(User $user, JobApplication $application): bool
    {
        return self::applications($user)->whereKey($application->id)->exists();
    }

    /** Pipeline moves, rejections and scheduling: HR, or the opening's hiring manager. */
    public static function canAct(User $user, JobApplication $application): bool
    {
        return self::manages($user) || ($user->hasPermission(P::RecruitmentView)
            && self::openings($user)->whereKey($application->job_opening_id)->exists());
    }
}
