<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\PaymentTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentTracker $tracker;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new PaymentTracker;
        $this->client = Client::create(['name' => 'Test Client', 'email' => 'client@test.com', 'status' => 'active']);
    }

    public function test_unpaid_invoice_is_pending(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 1000,
            'discount_amount' => 0,
            'due_date' => now()->addDays(30),
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $result = $this->tracker->recalc($invoice);

        $this->assertEquals('pending', $result->status);
        $this->assertEquals('pending', $result->payment_status);
    }

    public function test_full_payment_marks_paid(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 1000,
            'discount_amount' => 0,
            'due_date' => now()->addDays(30),
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'date' => now(),
            'method' => 'cash',
        ]);

        $result = $this->tracker->recalc($invoice);

        $this->assertEquals('paid', $result->status);
        $this->assertEquals('full', $result->payment_status);
    }

    public function test_half_payment_marks_half(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 1000,
            'discount_amount' => 0,
            'due_date' => now()->addDays(30),
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => 600,
            'date' => now(),
            'method' => 'cash',
        ]);

        $result = $this->tracker->recalc($invoice);

        $this->assertEquals('pending', $result->status);
        $this->assertEquals('half', $result->payment_status);
    }

    public function test_overdue_unpaid_marks_overdue(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 1000,
            'discount_amount' => 0,
            'due_date' => now()->subDays(5),
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $result = $this->tracker->recalc($invoice);

        $this->assertEquals('overdue', $result->status);
        $this->assertEquals('pending', $result->payment_status);
    }

    public function test_discount_on_unpaid_marks_discount(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 1000,
            'discount_amount' => 200,
            'due_date' => now()->addDays(30),
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $result = $this->tracker->recalc($invoice);

        $this->assertEquals('pending', $result->status);
        $this->assertEquals('discount', $result->payment_status);
    }

    public function test_discount_reduces_net_for_full_payment(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 1000,
            'discount_amount' => 200,
            'due_date' => now()->addDays(30),
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        // Pay 800 (which is 1000 - 200 discount = full)
        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => 800,
            'date' => now(),
            'method' => 'cash',
        ]);

        $result = $this->tracker->recalc($invoice);

        $this->assertEquals('paid', $result->status);
        $this->assertEquals('full', $result->payment_status);
    }

    public function test_installment_payment_marks_installment(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 1000,
            'discount_amount' => 0,
            'due_date' => now()->addDays(30),
            'status' => 'pending',
            'payment_status' => 'pending',
            'installment_plan' => json_encode(['total' => 3, 'paid' => 1, 'per_installment' => 333.33]),
        ]);

        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => 333.33,
            'date' => now(),
            'method' => 'bank',
        ]);

        $result = $this->tracker->recalc($invoice);

        $this->assertEquals('pending', $result->status);
        $this->assertEquals('installment', $result->payment_status);
    }
}
