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

class WorkflowAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(public Workflow $workflow, ?User $actor = null)
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
            ->subject('Workflow assigned: ' . $this->workflow->title)
            ->markdown('emails.workflow-assigned', [
                'recipient' => $notifiable,
                'workflow' => $this->workflow,
                'actor' => $this->actor,
                'url' => route('workflow') . '#wf-' . $this->workflow->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        return [
            'text' => 'You were assigned workflow "' . $this->workflow->title . '"'
                . ($this->workflow->deadline ? ' — due ' . \Carbon\Carbon::parse($this->workflow->deadline)->format('M d') : ''),
            'type' => 'info',
            'link' => route('workflow', absolute: false) . '#wf-' . $this->workflow->id,
        ];
    }
}
