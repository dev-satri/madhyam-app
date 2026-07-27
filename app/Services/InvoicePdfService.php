<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf as DomPdfFacade;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    public function generatePdf(Invoice $invoice, bool $saveToDisk = false): string|PDF
    {
        $invoice->load('client', 'payments');
        $agency = Setting::current();
        $payments = $invoice->payments()->orderBy('date', 'desc')->get();
        $totalPaid = (float) $payments->sum('amount');
        $netAmount = (float) $invoice->amount - (float) $invoice->discount_amount;
        $remaining = max(0, $netAmount - $totalPaid);
        $isInstallment = $invoice->payment_status === 'installment' && ! empty($invoice->installment_plan);
        $installmentPlan = null;

        if ($isInstallment) {
            $plan = is_array($invoice->installment_plan) ? $invoice->installment_plan : json_decode($invoice->installment_plan, true);
            $installmentPlan = $plan;
        }

        $pdf = DomPdfFacade::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'client' => $invoice->client,
            'agency' => $agency,
            'payments' => $payments,
            'totalPaid' => $totalPaid,
            'netAmount' => $netAmount,
            'remaining' => $remaining,
            'isInstallment' => $isInstallment,
            'installmentPlan' => $installmentPlan,
            'brandColor' => $agency->brand_color ?? '#4f46e5',
        ]);

        $pdf->setPaper('a4');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isHtml5ParserEnabled', true);

        if ($saveToDisk) {
            $path = "invoices/invoice-{$invoice->id}.pdf";
            Storage::disk('public')->put($path, $pdf->output());

            return $path;
        }

        return $pdf;
    }

    public function getFileName(Invoice $invoice): string
    {
        $clientName = $invoice->client->name ?? 'client';
        $safeName = preg_replace('/[^a-zA-Z0-9\-]/', '-', strtolower($clientName));

        return "invoice-{$invoice->id}-{$safeName}.pdf";
    }

    public function generateAndSaveAll(): array
    {
        $invoices = Invoice::with('client', 'payments')->get();
        $saved = [];

        foreach ($invoices as $invoice) {
            $path = $this->generatePdf($invoice, saveToDisk: true);
            $saved[] = [
                'invoice_id' => $invoice->id,
                'path' => $path,
            ];
        }

        return $saved;
    }
}
