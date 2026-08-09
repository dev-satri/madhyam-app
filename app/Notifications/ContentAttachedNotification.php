<?php

namespace App\Notifications;

use App\Models\Content;
use App\Models\User;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Notifications\Concerns\SkipsSelfActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContentAttachedNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    /**
     * @param Content $content
     * @param array   $attachments  The newly attached files
     * @param User|null $actor      Who attached them
     */
    public function __construct(
        public Content $content,
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
            ->subject(count($this->attachments) . ' file(s) attached to: ' . $this->content->title)
            ->markdown('emails.content-attached', [
                'recipient' => $notifiable,
                'content' => $this->content,
                'attachments' => $this->attachments,
                'actor' => $this->actor,
                'url' => route('content-planner') . '#content-' . $this->content->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $actorName = $this->actor?->name ?? 'Someone';
        $count = count($this->attachments);

        return [
            'text' => $actorName . ' attached ' . $count . ' file(s) to "' . $this->content->title . '"',
            'type' => 'info',
            'link' => route('content-planner', absolute: false) . '#content-' . $this->content->id,
        ];
    }
}
