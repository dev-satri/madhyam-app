<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Notifications\Concerns\SkipsSelfActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(public Task $task, ?User $actor = null)
    {
        $this->actor = $actor;
    }

    public function via(object $notifiable): array
    {
        return ['mail', InAppDatabaseChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Completed: ' . $this->task->title)
            ->markdown('emails.task-completed', [
                'recipient' => $notifiable,
                'task' => $this->task,
                'kind' => $this->kindLabel(),
                'kindLower' => strtolower($this->kindLabel()),
                'actor' => $this->actor,
                'url' => route('tasks') . '#task-' . $this->task->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $actorName = $this->actor?->name ?? 'A team member';

        return [
            'text' => $actorName . ' completed ' . strtolower($this->kindLabel()) . ' "' . $this->task->title . '"',
            'type' => 'success',
            'link' => route('tasks', absolute: false) . '#task-' . $this->task->id,
        ];
    }

    protected function kindLabel(): string
    {
        return match ($this->task->type) {
            'shoot' => 'Shoot',
            'editing' => 'Editing task',
            default => 'Task',
        };
    }
}
