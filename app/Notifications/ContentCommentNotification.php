<?php

namespace App\Notifications;

use App\Models\ClientAccount;
use App\Models\Comment;
use App\Models\Content;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Notifications\Concerns\SkipsSelfActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ContentCommentNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(public Comment $comment, public Content $content, mixed $actor = null)
    {
        $this->actor = $actor;
    }

    public function via(object $notifiable): array
    {
        return ['mail', InAppDatabaseChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->recipientUrl($notifiable);

        return (new MailMessage)
            ->subject('New comment on: ' . $this->content->title)
            ->markdown('emails.content-comment', [
                'recipient' => $notifiable,
                'content' => $this->content,
                'comment' => $this->comment,
                'actor' => $this->actor,
                'url' => $url,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $actorName = $this->actor?->name ?? 'Someone';
        $preview = Str::limit(trim($this->comment->body ?? $this->comment->text ?? ''), 80);

        $link = $notifiable instanceof ClientAccount
            ? route('client.calendar', absolute: false)
            : route('content-planner', absolute: false) . '#content-' . $this->content->id;

        return [
            'text' => $actorName . ' commented on "' . $this->content->title . '": ' . $preview,
            'type' => 'info',
            'link' => $link,
        ];
    }

    private function recipientUrl(object $notifiable): string
    {
        if ($notifiable instanceof ClientAccount) {
            return route('client.calendar');
        }

        return route('content-planner') . '#content-' . $this->content->id;
    }
}
