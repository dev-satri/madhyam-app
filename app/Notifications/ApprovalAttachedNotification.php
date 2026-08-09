<?php

namespace App\Notifications;

use App\Models\Approval;
use App\Models\User;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Notifications\Concerns\SkipsSelfActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalAttachedNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(
        public Approval $approval,
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
            ->subject(count($this->attachments) . ' file(s) attached to: ' . $this->approval->title)
            ->markdown('emails.approval-attached', [
                'recipient' => $notifiable,
                'approval' => $this->approval,
                'attachments' => $this->attachments,
                'actor' => $this->actor,
                'url' => route('approvals') . '#approval-' . $this->approval->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $actorName = $this->actor?->name ?? 'Someone';
        $count = count($this->attachments);

        return [
            'text' => $actorName . ' attached ' . $count . ' file(s) to "' . $this->approval->title . '"',
            'type' => 'info',
            'link' => route('approvals', absolute: false) . '#approval-' . $this->approval->id,
        ];
    }
}
