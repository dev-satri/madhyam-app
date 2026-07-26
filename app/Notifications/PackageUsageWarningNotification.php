<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PackageUsageWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{category: string, used: int, limit: int, percent: int}  $usage
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
        $percent = $this->usage['percent'];

        return (new MailMessage)
            ->subject("{$category} usage at {$percent}% — {$packageName} package")
            ->markdown('emails.package-usage-warning', [
                'contactName' => $contactName,
                'client' => $this->client,
                'packageName' => $packageName,
                'category' => $category,
                'used' => $this->usage['used'],
                'limit' => $this->usage['limit'],
                'percent' => $percent,
                'url' => route('client.dashboard'),
            ]);
    }
}
