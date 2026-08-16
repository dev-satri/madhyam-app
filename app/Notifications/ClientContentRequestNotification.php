<?php

namespace App\Notifications;

use App\Models\ClientAccount;
use App\Models\Content;
use App\Notifications\Channels\InAppDatabaseChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ClientContentRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Content $content, public ClientAccount $client)
    {
        //
    }

    public function via(object $notifiable): array
    {
        return ['mail', InAppDatabaseChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $clientName = $this->client->name ?? 'A client';
        $contentDate = $this->content->date ? Carbon::parse($this->content->date)->format('M d, Y') : 'Not specified';

        return (new MailMessage)
            ->subject('New Content Request from ' . $clientName)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line($clientName . ' has submitted a new content request.')
            ->line('**Title:** ' . $this->content->title)
            ->line('**Preferred Date:** ' . $contentDate)
            ->line('**Description:** ' . Str::limit($this->content->caption, 100))
            ->action('View Request', route('content-planner') . '#content-' . $this->content->id)
            ->line('Please review and assign this content to a team member.');
    }

    public function toInApp(object $notifiable): array
    {
        $clientName = $this->client->name ?? 'Client';

        return [
            'text' => $clientName . ' requested content: "' . $this->content->title . '"'
                . ($this->content->date ? ' for ' . Carbon::parse($this->content->date)->format('M d') : ''),
            'type' => 'info',
            'link' => route('content-planner', absolute: false) . '#content-' . $this->content->id,
        ];
    }
}
