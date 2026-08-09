<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\User;
use App\Models\Workflow;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Notifications\Concerns\SkipsSelfActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class WorkflowCommentNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(public Comment $comment, public Workflow $workflow, ?User $actor = null)
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
            ->subject('New comment on: ' . $this->workflow->title)
            ->markdown('emails.workflow-comment', [
                'recipient' => $notifiable,
                'workflow' => $this->workflow,
                'comment' => $this->comment,
                'actor' => $this->actor,
                'url' => route('workflow') . '#wf-' . $this->workflow->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $actorName = $this->actor?->name ?? 'Someone';
        $preview = Str::limit(trim($this->comment->body ?? $this->comment->text ?? ''), 80);

        return [
            'text' => $actorName . ' commented on "' . $this->workflow->title . '": ' . $preview,
            'type' => 'info',
            'link' => route('workflow', absolute: false) . '#wf-' . $this->workflow->id,
        ];
    }
}
