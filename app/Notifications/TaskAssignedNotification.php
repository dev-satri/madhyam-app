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

class TaskAssignedNotification extends Notification implements ShouldQueue
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
            ->subject($this->kindLabel() . ' assigned: ' . $this->task->title)
            ->markdown('emails.task-assigned', [
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
        return [
            'text' => 'You were assigned ' . strtolower($this->kindLabel()) . ' "' . $this->task->title . '"'
                . ($this->task->due_date ? ' — due ' . $this->task->due_date->format('M d, Y') : ''),
            'type' => 'info',
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
