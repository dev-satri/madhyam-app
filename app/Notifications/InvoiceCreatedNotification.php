<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class InvoiceCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

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

        $isInstallment = $this->invoice->payment_status === 'installment' && ! empty($this->invoice->installment_plan);
        $installmentInfo = '';
        if ($isInstallment) {
            $plan = is_array($this->invoice->installment_plan) ? $this->invoice->installment_plan : json_decode($this->invoice->installment_plan, true);
            $total = (int) ($plan['totalInstallments'] ?? 0);
            $perInstallment = number_format((float) ($plan['amountPerInstallment'] ?? 0), 2);
            $installmentInfo = " ({$total} installments of NPR {$perInstallment} each)";
        }

        $mail = (new MailMessage)
            ->subject('New Invoice — NPR ' . $netAmount . ' due ' . $dueDate)
            ->markdown('emails.invoice-created', [
                'contactName' => $contactName,
                'invoice' => $this->invoice,
                'client' => $client,
                'netAmount' => $netAmount,
                'dueDate' => $dueDate,
                'isInstallment' => $isInstallment,
                'installmentInfo' => $installmentInfo,
                'url' => route('client.billing'),
                'pdfUrl' => route('invoices.pdf.view', $this->invoice->id),
            ]);

        // Attach PDF invoice
        try {
            $pdfService = app(InvoicePdfService::class);
            $pdf = $pdfService->generatePdf($this->invoice);
            $fileName = $pdfService->getFileName($this->invoice);
            $pdfContent = $pdf->output();
            $tempPath = storage_path("app/temp/{$fileName}");

            if (! is_dir(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            file_put_contents($tempPath, $pdfContent);
            $mail->attach($tempPath, ['as' => $fileName, 'mime' => 'application/pdf']);
        } catch (\Exception $e) {
            Log::warning('Failed to attach PDF to invoice created notification', [
                'invoice_id' => $this->invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mail;
    }
}
