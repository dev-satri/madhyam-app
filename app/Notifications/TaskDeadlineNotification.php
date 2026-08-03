<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Support\NepaliDate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskDeadlineNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'tomorrow'|'today'  $when
     */
    public function __construct(public Task $task, public string $when = 'tomorrow') {}

    public function via(object $notifiable): array
    {
        return ['mail', InAppDatabaseChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline() . ': ' . $this->task->title)
            ->markdown('emails.task-deadline', [
                'recipient' => $notifiable,
                'task' => $this->task,
                'kind' => $this->kindLabel(),
                'kindLower' => strtolower($this->kindLabel()),
                'when' => $this->when,
                'headline' => $this->headline(),
                'url' => route('tasks') . '#task-' . $this->task->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        return [
            'text' => $this->headline() . ': "' . $this->task->title . '"'
                . ($this->task->due_date ? ' — due ' . NepaliDate::display($this->task->due_date) : ''),
            'type' => $this->when === 'today' ? 'warning' : 'info',
            'link' => route('tasks', absolute: false) . '#task-' . $this->task->id,
        ];
    }

    protected function headline(): string
    {
        return $this->when === 'today'
            ? 'Due today'
            : 'Due tomorrow';
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
