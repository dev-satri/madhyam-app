<?php

namespace App\Notifications;

use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Notifications\Concerns\SkipsSelfActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TaskCommentNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(public TaskComment $comment, ?User $actor = null)
    {
        $this->actor = $actor;
    }

    public function via(object $notifiable): array
    {
        return ['mail', InAppDatabaseChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->comment->task;

        return (new MailMessage)
            ->subject('New comment on: ' . $task->title)
            ->markdown('emails.task-comment', [
                'recipient' => $notifiable,
                'task' => $task,
                'kind' => $this->kindLabel($task->type),
                'kindLower' => strtolower($this->kindLabel($task->type)),
                'comment' => $this->comment,
                'actor' => $this->actor,
                'url' => route('tasks') . '#task-' . $task->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $task = $this->comment->task;
        $actorName = $this->actor?->name ?? 'Someone';
        $preview = Str::limit(trim($this->comment->text), 80);

        return [
            'text' => $actorName . ' commented on "' . $task->title . '": ' . $preview,
            'type' => 'info',
            'link' => route('tasks', absolute: false) . '#task-' . $task->id,
        ];
    }

    protected function kindLabel(string $type): string
    {
        return match ($type) {
            'shoot' => 'Shoot',
            'editing' => 'Editing task',
            default => 'Task',
        };
    }
}
