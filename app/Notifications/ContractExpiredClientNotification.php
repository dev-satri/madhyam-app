<?php

namespace App\Notifications;

use App\Models\Client;
use App\Support\NepaliDate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractExpiredClientNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Client $client,
        public int $daysPast,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contactName = $this->client->contact ?? $this->client->name;
        $packageName = $this->client->linkedPackage?->name ?? ucfirst($this->client->package);
        $expiryDate = NepaliDate::display($this->client->contract_end);
        $monthlyAmount = number_format($this->client->amount, 2);
        $dayWord = $this->daysPast === 1 ? 'day' : 'days';

        return (new MailMessage)
            ->subject("Your {$packageName} contract has expired")
            ->markdown('emails.contract-expired', [
                'contactName' => $contactName,
                'client' => $this->client,
                'packageName' => $packageName,
                'expiryDate' => $expiryDate,
                'monthlyAmount' => $monthlyAmount,
                'daysPast' => $this->daysPast,
                'dayWord' => $dayWord,
                'url' => route('client.billing'),
            ]);
    }
}
