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

class TaskAttachedNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(
        public Task $task,
        public array $attachments,
        ?User $actor = null,
    ) {
        $this->actor = $actor;
    }

    public function via(object $notifiable): array
    {
        return ['mail', InAppDatabaseChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(count($this->attachments) . ' file(s) attached to: ' . $this->task->title)
            ->markdown('emails.task-attached', [
                'recipient' => $notifiable,
                'task' => $this->task,
                'attachments' => $this->attachments,
                'actor' => $this->actor,
                'url' => route('tasks') . '#task-' . $this->task->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $actorName = $this->actor?->name ?? 'Someone';
        $count = count($this->attachments);

        return [
            'text' => $actorName . ' attached ' . $count . ' file(s) to "' . $this->task->title . '"',
            'type' => 'info',
            'link' => route('tasks', absolute: false) . '#task-' . $this->task->id,
        ];
    }
}
