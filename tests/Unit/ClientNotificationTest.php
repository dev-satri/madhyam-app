<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Notifications\ContractExpiredClientNotification;
use App\Notifications\ContractExpiryClientNotification;
use App\Notifications\InvoiceCreatedNotification;
use App\Notifications\InvoiceDueReminderNotification;
use App\Notifications\InvoiceOverdueNotification;
use App\Notifications\PackageUsageLimitReachedNotification;
use App\Notifications\PackageUsageWarningNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name' => 'Acme Corp',
            'contact' => 'Jane Smith',
            'email' => 'jane@acme.com',
            'package' => 'basic',
            'amount' => 15000,
            'status' => 'active',
            'contract_start' => now()->subMonth(),
            'contract_end' => now()->addDays(10),
        ]);

        $this->account = ClientAccount::create([
            'client_id' => $this->client->id,
            'email' => 'jane@acme.com',
            'name' => 'Jane Smith',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
    }

    // ── InvoiceCreatedNotification ──────────────────────────────

    public function test_invoice_created_mail_contains_amount(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(7),
        ]);

        $notification = new InvoiceCreatedNotification($invoice);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('15,000', $mail->subject);
        $this->assertStringContainsString('New Invoice', $mail->subject);
    }

    public function test_invoice_created_mail_renders(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(7),
        ]);

        $notification = new InvoiceCreatedNotification($invoice);
        $rendered = (string) $notification->toMail($this->account)->render();

        $this->assertStringContainsString('Jane Smith', $rendered);
        $this->assertStringContainsString('15,000', $rendered);
    }

    // ── InvoiceDueReminderNotification ──────────────────────────

    public function test_due_reminder_7_days(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(7),
        ]);

        $notification = new InvoiceDueReminderNotification($invoice, 7);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('7 days', $mail->subject);
    }

    public function test_due_reminder_1_day(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDay(),
        ]);

        $notification = new InvoiceDueReminderNotification($invoice, 1);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('1 day', $mail->subject);
    }

    // ── InvoiceOverdueNotification ──────────────────────────────

    public function test_overdue_notification_subject(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'overdue',
            'payment_status' => 'pending',
            'due_date' => now()->subDays(3),
        ]);

        $notification = new InvoiceOverdueNotification($invoice, 3);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('OVERDUE', $mail->subject);
        $this->assertStringContainsString('15,000', $mail->subject);
    }

    public function test_overdue_notification_renders(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'overdue',
            'payment_status' => 'pending',
            'due_date' => now()->subDays(5),
        ]);

        $notification = new InvoiceOverdueNotification($invoice, 5);
        $rendered = (string) $notification->toMail($this->account)->render();

        $this->assertStringContainsString('5 days', $rendered);
        $this->assertStringContainsString('overdue', $rendered);
    }

    // ── ContractExpiryClientNotification ────────────────────────

    public function test_contract_expiry_7_days(): void
    {
        $notification = new ContractExpiryClientNotification($this->client, 7);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('expiring', strtolower($mail->subject));
    }

    public function test_contract_expiry_1_day(): void
    {
        $notification = new ContractExpiryClientNotification($this->client, 1);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('tomorrow', strtolower($mail->subject));
    }

    public function test_contract_expiry_renders(): void
    {
        $notification = new ContractExpiryClientNotification($this->client, 3);
        $rendered = (string) $notification->toMail($this->account)->render();

        $this->assertStringContainsString('Basic', $rendered);
        $this->assertStringContainsString('3 days', $rendered);
    }

    // ── ContractExpiredClientNotification ───────────────────────

    public function test_contract_expired_subject(): void
    {
        $notification = new ContractExpiredClientNotification($this->client, 5);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('expired', strtolower($mail->subject));
    }

    public function test_contract_expired_renders(): void
    {
        $notification = new ContractExpiredClientNotification($this->client, 10);
        $rendered = (string) $notification->toMail($this->account)->render();

        $this->assertStringContainsString('10 days', $rendered);
        $this->assertStringContainsString('expired', $rendered);
    }

    // ── PackageUsageWarningNotification ─────────────────────────

    public function test_usage_warning_subject(): void
    {
        $notification = new PackageUsageWarningNotification($this->client, [
            'category' => 'content',
            'used' => 24,
            'limit' => 30,
            'percent' => 80,
        ]);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('80%', $mail->subject);
        $this->assertStringContainsString('Content', $mail->subject);
    }

    public function test_usage_warning_renders(): void
    {
        $notification = new PackageUsageWarningNotification($this->client, [
            'category' => 'storage',
            'used' => 800,
            'limit' => 1024,
            'percent' => 78,
        ]);
        $rendered = (string) $notification->toMail($this->account)->render();

        $this->assertStringContainsString('Storage', $rendered);
        $this->assertStringContainsString('800', $rendered);
    }

    // ── PackageUsageLimitReachedNotification ────────────────────

    public function test_usage_limit_subject(): void
    {
        $notification = new PackageUsageLimitReachedNotification($this->client, [
            'category' => 'workflow',
            'used' => 20,
            'limit' => 20,
            'percent' => 100,
        ]);
        $mail = $notification->toMail($this->account);

        $this->assertStringContainsString('limit reached', strtolower($mail->subject));
        $this->assertStringContainsString('Workflow', $mail->subject);
    }

    public function test_usage_limit_renders(): void
    {
        $notification = new PackageUsageLimitReachedNotification($this->client, [
            'category' => 'content',
            'used' => 30,
            'limit' => 30,
            'percent' => 100,
        ]);
        $rendered = (string) $notification->toMail($this->account)->render();

        $this->assertStringContainsString('Content', $rendered);
        $this->assertStringContainsString('30 out of 30', $rendered);
    }

    // ── Queue contract ──────────────────────────────────────────

    public function test_all_client_notifications_implement_should_queue(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(7),
        ]);

        $notifications = [
            new InvoiceCreatedNotification($invoice),
            new InvoiceDueReminderNotification($invoice, 7),
            new InvoiceOverdueNotification($invoice, 3),
            new ContractExpiryClientNotification($this->client, 7),
            new ContractExpiredClientNotification($this->client, 5),
            new PackageUsageWarningNotification($this->client, ['category' => 'content', 'used' => 24, 'limit' => 30, 'percent' => 80]),
            new PackageUsageLimitReachedNotification($this->client, ['category' => 'content', 'used' => 30, 'limit' => 30, 'percent' => 100]),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
        }
    }
}
