<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractExpiryClientNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Client $client,
        public int $daysUntil,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contactName = $this->client->contact ?? $this->client->name;
        $packageName = $this->client->linkedPackage?->name ?? ucfirst($this->client->package);
        $expiryDate = $this->client->contract_end->format('M d, Y');
        $monthlyAmount = number_format($this->client->amount, 2);
        $dayWord = $this->daysUntil === 1 ? 'day' : 'days';

        $subject = match (true) {
            $this->daysUntil <= 1 => "Your {$packageName} contract expires tomorrow",
            $this->daysUntil <= 3 => "Your {$packageName} contract expires in {$this->daysUntil} days",
            default => "Reminder: {$packageName} contract expiring on {$expiryDate}",
        };

        return (new MailMessage)
            ->subject($subject)
            ->markdown('emails.contract-expiry-reminder', [
                'contactName' => $contactName,
                'client' => $this->client,
                'packageName' => $packageName,
                'expiryDate' => $expiryDate,
                'monthlyAmount' => $monthlyAmount,
                'daysUntil' => $this->daysUntil,
                'dayWord' => $dayWord,
                'url' => route('client.billing'),
            ]);
    }
}
