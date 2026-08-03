<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\InvoicePdfService;
use App\Support\NepaliDate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class InvoicePaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public InvoicePayment $payment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->invoice->client;
        $contactName = $client->contact ?? $client->name;
        $paymentAmount = number_format($this->payment->amount, 2);
        $totalPaid = number_format($this->invoice->total_paid, 2);
        $netAmount = number_format($this->invoice->net_amount, 2);
        $remaining = number_format(max(0, $this->invoice->net_amount - $this->invoice->total_paid), 2);
        $method = ucfirst($this->payment->method);
        $paymentDate = NepaliDate::display($this->payment->date);
        $isFullyPaid = $this->invoice->status === 'paid';
        $note = $this->payment->note;

        // Installment context
        $isInstallment = $this->invoice->payment_status === 'installment' && ! empty($this->invoice->installment_plan);
        $installmentContext = null;

        if ($isInstallment) {
            $plan = is_array($this->invoice->installment_plan) ? $this->invoice->installment_plan : json_decode($this->invoice->installment_plan, true);
            $amountPerInstallment = (float) ($plan['amountPerInstallment'] ?? 0);
            $totalInstallments = (int) ($plan['totalInstallments'] ?? 0);
            $totalPaidAmt = (float) $this->invoice->total_paid;
            $paidInstallments = $amountPerInstallment > 0 ? (int) floor($totalPaidAmt / $amountPerInstallment) : 0;
            $currentInstallmentPaid = $totalPaidAmt - ($paidInstallments * $amountPerInstallment);
            $currentInstallmentRemaining = max(0, $amountPerInstallment - $currentInstallmentPaid);

            $installmentContext = [
                'amount_per_installment' => number_format($amountPerInstallment, 2),
                'total_installments' => $totalInstallments,
                'paid_installments' => min($paidInstallments, $totalInstallments),
                'current_installment_number' => min($paidInstallments + 1, $totalInstallments),
                'current_installment_paid' => number_format($currentInstallmentPaid, 2),
                'current_installment_remaining' => number_format($currentInstallmentRemaining, 2),
            ];
        }

        $mail = (new MailMessage)
            ->subject($isFullyPaid
                ? "Payment Received — Invoice #{$this->invoice->id} Fully Paid ✓"
                : "Payment Received — NPR {$paymentAmount} for Invoice #{$this->invoice->id}")
            ->markdown('emails.invoice-payment-received', [
                'contactName' => $contactName,
                'invoice' => $this->invoice,
                'payment' => $this->payment,
                'client' => $client,
                'paymentAmount' => $paymentAmount,
                'totalPaid' => $totalPaid,
                'netAmount' => $netAmount,
                'remaining' => $remaining,
                'method' => $method,
                'methodRaw' => $this->payment->method,
                'paymentDate' => $paymentDate,
                'isFullyPaid' => $isFullyPaid,
                'note' => $note,
                'isInstallment' => $isInstallment,
                'installmentContext' => $installmentContext,
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

            // Ensure temp directory exists
            if (! is_dir(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            file_put_contents($tempPath, $pdfContent);
            $mail->attach($tempPath, ['as' => $fileName, 'mime' => 'application/pdf']);
        } catch (\Exception $e) {
            // PDF attachment failed — email still sends without PDF
            Log::warning('Failed to attach PDF to payment notification', [
                'invoice_id' => $this->invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mail;
    }
}
