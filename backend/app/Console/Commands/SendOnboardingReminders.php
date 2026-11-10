<?php

namespace App\Console\Commands;

use App\Audit\AuditEvents;
use App\Models\AuditLog;
use App\Models\OnboardingTask;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OnboardingTaskReminder;
use App\Security\Permission as P;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Emails people about onboarding tasks due within the lead time or overdue. At most one reminder per task per day:
 * the task is claimed with a conditional update before sending, so overlapping runs cannot double-send.
 */
class SendOnboardingReminders extends Command
{
    protected $signature = 'wingora:onboarding:reminders {--lead-days=2}';

    protected $description = 'Send reminders for onboarding tasks that are due soon or overdue';

    public function handle(): int
    {
        $today = now()->toDateString();
        $until = now()->addDays(max(0, (int) $this->option('lead-days')))->toDateString();
        $total = 0;
        Tenant::query()->where('status', 'active')->each(function (Tenant $tenant) use ($today, $until, &$total) {
            Tenancy::run($tenant, function () use ($tenant, $today, $until, &$total) {
                $sent = 0;
                $hr = null;
                OnboardingTask::query()->with(['plan.employee.manager', 'plan.employee.user', 'assigneeUser'])
                    ->whereIn('status', ['pending', 'returned'])->where('due_on', '<=', $until)
                    ->where(fn ($q) => $q->whereNull('last_reminded_on')->orWhere('last_reminded_on', '<', $today))
                    ->whereHas('plan', fn ($p) => $p->where('status', 'in_progress'))
                    ->chunkById(200, function ($tasks) use ($tenant, $today, &$sent, &$hr) {
                        foreach ($tasks as $task) {
                            $claimed = OnboardingTask::query()->whereKey($task->id)
                                ->where(fn ($q) => $q->whereNull('last_reminded_on')->orWhere('last_reminded_on', '<', $today))
                                ->update(['last_reminded_on' => $today]);
                            if ($claimed !== 1) {
                                continue;
                            }
                            $recipients = $task->assigneeUser ? collect([$task->assigneeUser]) : match ($task->assignee) {
                                'hr' => $hr ??= User::query()->where('tenant_id', $tenant->id)->where('status', 'active')->get()
                                    ->filter(fn (User $u) => $u->hasPermission(P::OnboardingManage))->values(),
                                default => collect(),
                            };
                            $recipients = $recipients->filter(fn ($u) => $u->status === 'active');
                            if ($recipients->isNotEmpty()) {
                                Notification::send($recipients, OnboardingTaskReminder::for($task));
                                $sent++;
                            }
                        }
                    });
                if ($sent > 0) {
                    AuditLog::record(AuditEvents::ONBOARDING_REMINDERS_SENT, $tenant, metadata: ['tasks' => $sent], tenantId: $tenant->id);
                }
                $total += $sent;
            });
        });
        $this->info("Reminders sent for {$total} task(s).");

        return self::SUCCESS;
    }
}
