<?php

namespace App\Onboarding;

use App\Audit\AuditEvents;
use App\Country\RuleCategory;
use App\Country\RuleResolver;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\LegalEntity;
use App\Models\OnboardingPlan;
use App\Models\OnboardingTask;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Security\Permission as P;
use App\Time\Approvers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class OnboardingService
{
    public function __construct(private readonly RuleResolver $rules) {}

    public function start(Employee $employee, OnboardingTemplate $template, string $startDate, User $actor, ?JobApplication $application = null): OnboardingPlan
    {
        return DB::transaction(function () use ($employee, $template, $startDate, $actor, $application) {
            abort_if(OnboardingPlan::query()->where('employee_id', $employee->id)->where('status', 'in_progress')->exists(), 409, __('This employee already has onboarding in progress.'));
            if ($template->legal_entity_id && (int) $template->legal_entity_id !== (int) $employee->legal_entity_id) {
                abort(422, __('This template is for another legal entity.'));
            }
            $plan = new OnboardingPlan;
            $plan->forceFill(['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id, 'onboarding_template_id' => $template->id, 'job_application_id' => $application?->id,
                'start_date' => $startDate, 'status' => 'in_progress', 'created_by' => $actor->id])->saveQuietly();
            $start = CarbonImmutable::parse($startDate);
            foreach ($template->tasks as $t) {
                $task = new OnboardingTask;
                $task->forceFill(['tenant_id' => $plan->tenant_id, 'onboarding_plan_id' => $plan->id, 'stage' => $t->stage, 'title' => $t->title, 'description' => $t->description,
                    'assignee' => $t->assignee, 'assignee_user_id' => $this->assigneeUser($t->assignee, $t->assignee_user_id, $employee),
                    'due_on' => $start->addDays($t->due_offset_days)->toDateString(), 'document_key' => $t->document_key, 'document_name' => $t->document_name,
                    'status' => 'pending', 'sort_order' => $t->sort_order])->saveQuietly();
            }
            AuditLog::record(AuditEvents::ONBOARDING_STARTED, $plan, metadata: ['employee' => $employee->public_id, 'template' => $template->public_id,
                'tasks' => $template->tasks->count(), 'start_date' => $startDate]);

            return $plan;
        });
    }

    /**
     * Employment documents configured for a legal entity's country (and state/province) in the approved
     * compliance rules, so nothing country-specific is hard-coded in onboarding.
     *
     * @return list<array{key: string, name: string, description: ?string, reference: ?string}>
     */
    public function countryDocuments(LegalEntity $entity, string $date): array
    {
        return $this->rules->effective($entity->country_code, $entity->subdivision_code, RuleCategory::Compliance, $date)
            ->flatMap(fn ($v) => $v->payload['requirements'] ?? [])
            ->unique('key')
            ->map(fn ($r) => ['key' => $r['key'], 'name' => $r['name'], 'description' => $r['description'] ?? null, 'reference' => $r['reference'] ?? null])
            ->values()->all();
    }

    public function canWork(User $user, OnboardingTask $task): bool
    {
        if (! $user->hasPermission(P::OnboardingTasksSelf) && ! $user->hasPermission(P::OnboardingManage)) {
            return false;
        }
        $plan = $task->plan;
        $employee = $plan->employee;

        return match ($task->assignee) {
            'hr' => $user->hasPermission(P::OnboardingManage),
            'manager' => $user->hasPermission(P::OnboardingManage) || Approvers::isManagerOf($user, $employee),
            'employee' => $user->employee?->id === $plan->employee_id && $user->hasPermission(P::OnboardingTasksSelf),
            default => $user->hasPermission(P::OnboardingManage) || (int) $task->assignee_user_id === (int) $user->id,
        };
    }

    /** Plans the user may open: HR sees all; others see their own, their reports', and any plan with a task assigned to them. */
    public function visiblePlans(User $user): Builder
    {
        $query = OnboardingPlan::query();
        if ($user->hasPermission(P::OnboardingView)) {
            return $query;
        }
        if (! $user->hasPermission(P::OnboardingTasksSelf)) {
            return $query->whereRaw('1 = 0');
        }
        $me = $user->employee?->id;

        return $query->where(fn ($q) => $q
            ->when($me, fn ($w) => $w->where('employee_id', $me)->orWhereIn('employee_id', Employee::query()->where('manager_id', $me)->select('id')))
            ->orWhereIn('id', OnboardingTask::query()->where('assignee', 'user')->where('assignee_user_id', $user->id)->select('onboarding_plan_id')));
    }

    /** Open tasks this user is responsible for, soonest first. */
    public function myTasks(User $user): Builder
    {
        $me = $user->employee?->id;
        $hr = $user->hasPermission(P::OnboardingManage);
        if (! $hr && ! $user->hasPermission(P::OnboardingTasksSelf)) {
            return OnboardingTask::query()->whereRaw('1 = 0');
        }

        return OnboardingTask::query()->whereIn('status', ['pending', 'returned', 'submitted'])
            ->whereHas('plan', fn ($p) => $p->where('status', 'in_progress'))
            ->where(function ($q) use ($user, $me, $hr) {
                $q->where(fn ($w) => $w->where('assignee', 'user')->where('assignee_user_id', $user->id));
                if ($hr) {
                    $q->orWhere('assignee', 'hr')->orWhere(fn ($w) => $w->where('assignee', '!=', 'hr')->where('status', 'submitted'));
                }
                if ($me && $user->hasPermission(P::OnboardingTasksSelf)) {
                    $q->orWhere(fn ($w) => $w->where('assignee', 'employee')->whereIn('onboarding_plan_id', OnboardingPlan::query()->where('employee_id', $me)->select('id')));
                    $q->orWhere(fn ($w) => $w->where('assignee', 'manager')->whereIn('onboarding_plan_id', OnboardingPlan::query()
                        ->whereIn('employee_id', Employee::query()->where('manager_id', $me)->select('id'))->select('id')));
                }
            })
            ->orderBy('due_on')->orderBy('id');
    }

    public function refresh(OnboardingPlan $plan): void
    {
        $open = $plan->tasks()->whereNotIn('status', OnboardingTask::DONE)->exists();
        if (! $open && $plan->status === 'in_progress') {
            $plan->forceFill(['status' => 'completed', 'completed_at' => now()])->saveQuietly();
        } elseif ($open && $plan->status === 'completed') {
            $plan->forceFill(['status' => 'in_progress', 'completed_at' => null])->saveQuietly();
        }
    }

    /** @return array{total: int, done: int, overdue: int, percent: int} */
    public static function progress(OnboardingPlan $plan): array
    {
        $tasks = $plan->tasks;
        $done = $tasks->whereIn('status', OnboardingTask::DONE)->count();
        $today = now()->toDateString();
        $overdue = $tasks->filter(fn ($t) => ! in_array($t->status, OnboardingTask::DONE, true) && $t->due_on->toDateString() < $today)->count();
        $total = $tasks->count();

        return ['total' => $total, 'done' => $done, 'overdue' => $overdue, 'percent' => $total === 0 ? 100 : intdiv($done * 100, $total)];
    }

    private function assigneeUser(string $assignee, ?int $named, Employee $employee): ?int
    {
        return match ($assignee) {
            'employee' => $employee->user_id,
            'manager' => $employee->manager?->user_id,
            'user' => $named,
            default => $named,
        };
    }
}
