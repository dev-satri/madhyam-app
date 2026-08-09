<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workflow;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Notifications\Concerns\SkipsSelfActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowAttachedNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(
        public Workflow $workflow,
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
            ->subject(count($this->attachments) . ' file(s) attached to: ' . $this->workflow->title)
            ->markdown('emails.workflow-attached', [
                'recipient' => $notifiable,
                'workflow' => $this->workflow,
                'attachments' => $this->attachments,
                'actor' => $this->actor,
                'url' => route('workflow') . '#wf-' . $this->workflow->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $actorName = $this->actor?->name ?? 'Someone';
        $count = count($this->attachments);

        return [
            'text' => $actorName . ' attached ' . $count . ' file(s) to "' . $this->workflow->title . '"',
            'type' => 'info',
            'link' => route('workflow', absolute: false) . '#wf-' . $this->workflow->id,
        ];
    }
}
