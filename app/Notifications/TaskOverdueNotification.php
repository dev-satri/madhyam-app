<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Channels\InAppDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Task $task, public int $daysOverdue = 1) {}

    public function via(object $notifiable): array
    {
        return ['mail', InAppDatabaseChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Overdue: ' . $this->task->title)
            ->markdown('emails.task-overdue', [
                'recipient' => $notifiable,
                'task' => $this->task,
                'kind' => $this->kindLabel(),
                'kindLower' => strtolower($this->kindLabel()),
                'daysOverdue' => $this->daysOverdue,
                'url' => route('tasks') . '#task-' . $this->task->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $day = $this->daysOverdue === 1 ? 'day' : 'days';

        return [
            'text' => 'Overdue by ' . $this->daysOverdue . ' ' . $day . ': "' . $this->task->title . '"',
            'type' => 'error',
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
