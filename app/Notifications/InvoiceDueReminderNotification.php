<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceDueReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public int $daysUntilDue,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->invoice->client;
        $contactName = $client->contact ?? $client->name;
        $netAmount = number_format($this->invoice->net_amount, 2);
        $dueDate = $this->invoice->due_date->format('M d, Y');
        $dayWord = $this->daysUntilDue === 1 ? 'day' : 'days';

        $urgency = match (true) {
            $this->daysUntilDue <= 1 => 'error',
            $this->daysUntilDue <= 3 => 'warning',
            default => 'info',
        };

        return (new MailMessage)
            ->subject("Payment due in {$this->daysUntilDue} {$dayWord} — NPR {$netAmount}")
            ->markdown('emails.invoice-due-reminder', [
                'contactName' => $contactName,
                'invoice' => $this->invoice,
                'client' => $client,
                'netAmount' => $netAmount,
                'dueDate' => $dueDate,
                'daysUntilDue' => $this->daysUntilDue,
                'dayWord' => $dayWord,
                'urgency' => $urgency,
                'url' => route('client.billing'),
            ]);
    }
}
