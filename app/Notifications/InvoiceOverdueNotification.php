<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public int $daysOverdue,
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
        $dayWord = $this->daysOverdue === 1 ? 'day' : 'days';

        return (new MailMessage)
            ->subject("OVERDUE — NPR {$netAmount} was due {$dueDate}")
            ->markdown('emails.invoice-overdue', [
                'contactName' => $contactName,
                'invoice' => $this->invoice,
                'client' => $client,
                'netAmount' => $netAmount,
                'dueDate' => $dueDate,
                'daysOverdue' => $this->daysOverdue,
                'dayWord' => $dayWord,
                'url' => route('client.billing'),
            ]);
    }
}
