<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentTracker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class InvoicePaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DatabaseSeeder::class]);

        $this->admin = User::where('role', 'super-admin')->first();
        $this->actingAs($this->admin);

        $this->clientId = DB::table('clients')->insertGetId([
            'name' => 'Test Client', 'email' => 'test@client.com',
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_create_invoice(): void
    {
        Livewire::test('pages.reports.index')
            ->call('openInvoiceForm')
            ->set('formClientId', $this->clientId)
            ->set('formAmount', '10000')
            ->set('formStatus', 'pending')
            ->set('formDueDate', now()->addDays(30)->format('Y-m-d'))
            ->set('formPaymentStatus', 'pending')
            ->call('saveInvoice');

        $this->assertDatabaseHas('invoices', [
            'client_id' => $this->clientId,
            'amount' => 10000,
            'status' => 'pending',
        ]);
    }

    public function test_full_payment_marks_paid(): void
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'client_id' => $this->clientId, 'amount' => 5000, 'status' => 'pending',
            'payment_status' => 'pending', 'due_date' => now()->addDays(30)->format('Y-m-d'),
            'discount_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.reports.index')
            ->call('openRecordPayment', $invoiceId)
            ->set('payAmount', '5000')
            ->set('payDate', now()->format('Y-m-d'))
            ->set('payMethod', 'cash')
            ->call('savePayment');

        $invoice = Invoice::find($invoiceId);
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals('full', $invoice->payment_status);
    }

    public function test_half_payment_marks_half(): void
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'client_id' => $this->clientId, 'amount' => 10000, 'status' => 'pending',
            'payment_status' => 'pending', 'due_date' => now()->addDays(30)->format('Y-m-d'),
            'discount_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.reports.index')
            ->call('openRecordPayment', $invoiceId)
            ->set('payAmount', '5000')
            ->set('payDate', now()->format('Y-m-d'))
            ->set('payMethod', 'bank')
            ->call('savePayment');

        $invoice = Invoice::find($invoiceId);
        $this->assertEquals('pending', $invoice->status);
        $this->assertEquals('half', $invoice->payment_status);
    }

    public function test_overdue_detection(): void
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'client_id' => $this->clientId, 'amount' => 5000, 'status' => 'pending',
            'payment_status' => 'pending', 'due_date' => now()->subDays(5)->format('Y-m-d'),
            'discount_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($invoice = Invoice::find($invoiceId)) {
            app(PaymentTracker::class)->recalc($invoice);
        }

        $this->assertEquals('overdue', Invoice::find($invoiceId)->status);
    }

    public function test_discount_reduces_net(): void
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'client_id' => $this->clientId, 'amount' => 10000, 'status' => 'pending',
            'payment_status' => 'pending', 'due_date' => now()->addDays(30)->format('Y-m-d'),
            'discount_amount' => 2000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.reports.index')
            ->call('openRecordPayment', $invoiceId)
            ->set('payAmount', '8000')
            ->set('payDate', now()->format('Y-m-d'))
            ->set('payMethod', 'cash')
            ->call('savePayment');

        $invoice = Invoice::find($invoiceId);
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals('full', $invoice->payment_status);
    }

    public function test_installment_payment(): void
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'client_id' => $this->clientId, 'amount' => 30000, 'status' => 'pending',
            'payment_status' => 'installment', 'due_date' => now()->addDays(60)->format('Y-m-d'),
            'discount_amount' => 0,
            'installment_plan' => json_encode(['totalInstallments' => 3, 'paidInstallments' => 0, 'amountPerInstallment' => 10000]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.reports.index')
            ->call('openRecordPayment', $invoiceId)
            ->set('payAmount', '10000')
            ->set('payDate', now()->format('Y-m-d'))
            ->set('payMethod', 'bank')
            ->call('savePayment');

        $invoice = Invoice::find($invoiceId);
        $this->assertEquals('pending', $invoice->status);
        $this->assertEquals('installment', $invoice->payment_status);
    }

    public function test_payment_creates_activity_log(): void
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'client_id' => $this->clientId, 'amount' => 1000, 'status' => 'pending',
            'payment_status' => 'pending', 'due_date' => now()->addDays(30)->format('Y-m-d'),
            'discount_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.reports.index')
            ->call('openRecordPayment', $invoiceId)
            ->set('payAmount', '1000')
            ->set('payDate', now()->format('Y-m-d'))
            ->set('payMethod', 'cash')
            ->call('savePayment');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
        ]);
    }
}
