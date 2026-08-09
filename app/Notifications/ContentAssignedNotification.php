<?php

namespace App\Notifications;

use App\Models\Content;
use App\Models\User;
use App\Notifications\Channels\InAppDatabaseChannel;
use App\Notifications\Concerns\SkipsSelfActor;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContentAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable, SkipsSelfActor;

    public function __construct(public Content $content, ?User $actor = null)
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
            ->subject('Content assigned: ' . $this->content->title)
            ->markdown('emails.content-assigned', [
                'recipient' => $notifiable,
                'content' => $this->content,
                'actor' => $this->actor,
                'url' => route('content-planner') . '#content-' . $this->content->id,
            ]);
    }

    public function toInApp(object $notifiable): array
    {
        return [
            'text' => 'You were assigned content "' . $this->content->title . '"'
                . ($this->content->date ? ' — due ' . Carbon::parse($this->content->date)->format('M d') : ''),
            'type' => 'info',
            'link' => route('content-planner', absolute: false) . '#content-' . $this->content->id,
        ];
    }
}
