<?php

namespace App\Notifications;

use App\Models\OnboardingTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Reminder for an open onboarding task. Contains no documents or personal data beyond the new hire's name. */
class OnboardingTaskReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $title, public readonly string $employee, public readonly string $dueOn, public readonly bool $overdue) {}

    public static function for(OnboardingTask $task): self
    {
        return new self($task->title, $task->plan->employee->fullName(), $task->due_on->toDateString(), $task->due_on->toDateString() < now()->toDateString());
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->overdue ? 'Overdue' : 'Due soon').': '.$this->title)
            ->line(__('Onboarding task for :name: :title', ['name' => $this->employee, 'title' => $this->title]))
            ->line(__('Due on :date.', ['date' => $this->dueOn]))
            ->action(__('Open Wingora'), rtrim((string) config('app.frontend_url', config('app.url')), '/').'/onboarding');
    }
}
