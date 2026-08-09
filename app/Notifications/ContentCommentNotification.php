<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Content;
use App\Models\User;
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

    public function __construct(public Comment $comment, public Content $content, ?User $actor = null)
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
            ->subject('New comment on: ' . $this->content->title)
            ->markdown('emails.content-comment', [
                'recipient' => $notifiable,
                'content' => $this->content,
                'comment' => $this->comment,
                'actor' => $this->actor,
                'url' => route('content-planner') . '#content-' . $this->content->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        $actorName = $this->actor?->name ?? 'Someone';
        $preview = Str::limit(trim($this->comment->body ?? $this->comment->text ?? ''), 80);

        return [
            'text' => $actorName . ' commented on "' . $this->content->title . '": ' . $preview,
            'type' => 'info',
            'link' => route('content-planner', absolute: false) . '#content-' . $this->content->id,
        ];
    }
}
