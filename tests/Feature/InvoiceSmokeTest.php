<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Setting;
use App\Services\InvoicePdfService;
use App\Services\PaymentTracker;
use Barryvdh\DomPDF\PDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name' => 'Smoke Test Client',
            'email' => 'smoke@test.com',
            'phone' => '+977-9800000000',
            'status' => 'active',
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'agency_name' => 'Madhyam Agency',
            'agency_email' => 'admin@madhyam.com',
            'agency_phone' => '+977-9800000000',
            'currency' => 'NPR',
            'brand_color' => '#4f46e5',
        ]);
    }

    /**
     * Smoke test: Full-payment invoice lifecycle.
     *
     * 1. Create invoice for Rs. 25,000
     * 2. Record full payment
     * 3. Verify status flips to "paid" / "full"
     * 4. Generate PDF — assert it returns a valid PDF object with content
     */
    public function test_full_payment_smoke(): void
    {
        // ── Create invoice ──
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 25000,
            'discount_amount' => 0,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(30),
            'description' => 'Smoke test — full payment',
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'pending',
        ]);

        // ── Record full payment ──
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => 25000,
            'date' => now(),
            'method' => 'bank',
            'note' => 'Full payment received',
        ]);

        // ── Recalc via PaymentTracker ──
        $tracker = new PaymentTracker;
        $invoice->refresh();
        $tracker->recalc($invoice);

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status, 'Invoice status should be paid');
        $this->assertEquals('full', $invoice->payment_status, 'Payment status should be full');
        $this->assertNotNull($invoice->paid_date, 'Paid date should be set');

        // ── Verify payment is recorded ──
        $this->assertDatabaseHas('invoice_payments', [
            'invoice_id' => $invoice->id,
            'amount' => 25000,
            'method' => 'bank',
        ]);

        // ── Generate PDF ──
        $pdfService = app(InvoicePdfService::class);
        $pdf = $pdfService->generatePdf($invoice);

        $this->assertInstanceOf(PDF::class, $pdf, 'Should return a DomPDF instance');

        $output = $pdf->output();
        $this->assertNotEmpty($output, 'PDF output should not be empty');
        $this->assertStringContainsString('%PDF', $output, 'Should be a valid PDF');
        $this->assertGreaterThan(1000, strlen($output), 'PDF should have meaningful content');

        // ── Verify filename generation ──
        $filename = $pdfService->getFileName($invoice);
        $this->assertStringContainsString("invoice-{$invoice->id}", $filename);
        $this->assertStringEndsWith('.pdf', $filename);

        // ── Verify save to disk ──
        Storage::fake('public');
        $path = $pdfService->generatePdf($invoice, saveToDisk: true);
        $this->assertEquals("invoices/invoice-{$invoice->id}.pdf", $path);
    }

    /**
     * Smoke test: Installment invoice lifecycle.
     *
     * 1. Create invoice for Rs. 60,000 with 3-installment plan
     * 2. Pay first installment (Rs. 20,000)
     * 3. Verify status stays "pending" / "installment"
     * 4. Pay second installment (Rs. 20,000)
     * 5. Verify status stays "pending" / "installment" but paidInstallments increments
     * 6. Pay final installment (Rs. 20,000)
     * 7. Verify status flips to "paid" / "full"
     * 8. Generate PDF at each stage — assert valid PDF output
     */
    public function test_installment_payment_smoke(): void
    {
        $totalAmount = 60000;
        $perInstallment = 20000;

        // ── Create installment invoice ──
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => $totalAmount,
            'discount_amount' => 0,
            'status' => 'pending',
            'payment_status' => 'installment',
            'due_date' => now()->addDays(90),
            'description' => 'Smoke test — installment plan',
            'installment_plan' => json_encode([
                'totalInstallments' => 3,
                'paidInstallments' => 0,
                'amountPerInstallment' => $perInstallment,
            ]),
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'payment_status' => 'installment',
        ]);

        $tracker = app(PaymentTracker::class);
        $pdfService = app(InvoicePdfService::class);

        // ── Installment 1 ──
        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => $perInstallment,
            'date' => now(),
            'method' => 'bank',
            'note' => 'Installment 1 of 3',
        ]);

        $invoice->refresh();
        $tracker->recalc($invoice);
        $invoice->refresh();

        $this->assertEquals('pending', $invoice->status, 'Should stay pending after installment 1');
        $this->assertEquals('installment', $invoice->payment_status, 'Should remain installment');
        $this->assertEquals(1, $invoice->installment_plan['paidInstallments'], 'paidInstallments should be 1');
        $this->assertEquals(20000, $invoice->total_paid, 'Total paid should be 20000');

        $pdf1 = $pdfService->generatePdf($invoice);
        $this->assertStringContainsString('%PDF', $pdf1->output(), 'PDF 1 should be valid');

        // ── Installment 2 ──
        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => $perInstallment,
            'date' => now(),
            'method' => 'khalti',
            'note' => 'Installment 2 of 3',
        ]);

        $invoice->refresh();
        $tracker->recalc($invoice);
        $invoice->refresh();

        $this->assertEquals('pending', $invoice->status, 'Should stay pending after installment 2');
        $this->assertEquals('half', $invoice->payment_status, '>=50% paid triggers half status');
        $this->assertEquals(2, $invoice->installment_plan['paidInstallments'], 'paidInstallments should be 2');
        $this->assertEquals(40000, $invoice->total_paid, 'Total paid should be 40000');

        $pdf2 = $pdfService->generatePdf($invoice);
        $this->assertStringContainsString('%PDF', $pdf2->output(), 'PDF 2 should be valid');

        // ── Installment 3 (final) ──
        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => $perInstallment,
            'date' => now(),
            'method' => 'cash',
            'note' => 'Installment 3 of 3 — final payment',
        ]);

        $invoice->refresh();
        $tracker->recalc($invoice);
        $invoice->refresh();

        $this->assertEquals('paid', $invoice->status, 'Should flip to paid after final installment');
        $this->assertEquals('full', $invoice->payment_status, 'Should be full after complete payment');
        $this->assertEquals(3, $invoice->installment_plan['paidInstallments'], 'All 3 installments paid');
        $this->assertEquals(60000, $invoice->total_paid, 'Total paid should equal invoice amount');

        $pdf3 = $pdfService->generatePdf($invoice);
        $output3 = $pdf3->output();
        $this->assertStringContainsString('%PDF', $output3, 'PDF 3 should be valid');
        $this->assertGreaterThan(1000, strlen($output3), 'Final PDF should have full content');

        // ── Verify all 3 payments exist ──
        $this->assertCount(3, $invoice->payments, 'Should have 3 payment records');
        $this->assertEquals(60000, $invoice->payments->sum('amount'), 'Sum of payments should equal invoice amount');
    }
}
