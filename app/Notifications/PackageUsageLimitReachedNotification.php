<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PackageUsageLimitReachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{category: string, used: int, limit: int, percent: int, deliverables?: array<int,array{type:string,used:int,limit:int,percent:int,over:bool}>}  $usage
     */
    public function __construct(
        public Client $client,
        public array $usage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contactName = $this->client->contact ?? $this->client->name;
        $packageName = $this->client->linkedPackage?->name ?? ucfirst($this->client->package);
        $category = ucfirst($this->usage['category']);

        return (new MailMessage)
            ->subject("{$category} limit reached — {$packageName} package")
            ->markdown('emails.package-usage-limit', [
                'contactName' => $contactName,
                'client' => $this->client,
                'packageName' => $packageName,
                'category' => $category,
                'used' => $this->usage['used'],
                'limit' => $this->usage['limit'],
                'deliverables' => $this->usage['deliverables'] ?? [],
                'url' => route('client.dashboard'),
            ]);
    }
}
