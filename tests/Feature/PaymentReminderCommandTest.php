<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Notification as NotificationModel;
use App\Models\NotificationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationRule::create([
            'name' => 'Payment Reminder',
            'trigger' => 'payment-reminder',
            'days' => 7,
            'active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'Test Client',
            'email' => 'test@client.com',
            'package' => 'basic',
            'amount' => 15000,
            'status' => 'active',
            'contract_start' => now()->subMonth(),
            'contract_end' => now()->addMonths(2),
        ]);

        $this->account = ClientAccount::create([
            'client_id' => $this->client->id,
            'email' => 'test@client.com',
            'name' => 'Test Client',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
    }

    public function test_sends_7_day_reminder(): void
    {
        Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(7),
        ]);

        $this->artisan('notifications:payment-reminders')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
            'type' => 'warning',
        ]);
    }

    public function test_sends_3_day_reminder(): void
    {
        Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        $this->artisan('notifications:payment-reminders')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
            'type' => 'warning',
        ]);
    }

    public function test_sends_1_day_reminder(): void
    {
        Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDay(),
        ]);

        $this->artisan('notifications:payment-reminders')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
            'type' => 'error',
        ]);
    }

    public function test_sends_overdue_reminder(): void
    {
        Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'overdue',
            'payment_status' => 'pending',
            'due_date' => now()->subDays(3),
        ]);

        $this->artisan('notifications:payment-reminders')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
            'type' => 'error',
        ]);
    }

    public function test_skips_paid_invoices(): void
    {
        Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'paid',
            'payment_status' => 'full',
            'due_date' => now()->addDays(7),
            'paid_date' => now(),
        ]);

        $this->artisan('notifications:payment-reminders')
            ->assertSuccessful();

        $this->assertEquals(0, NotificationModel::where('client_id', $this->client->id)->count());
    }

    public function test_creates_in_app_notification(): void
    {
        Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(7),
        ]);

        $this->artisan('notifications:payment-reminders')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
        ]);
    }

    public function test_success_when_no_rule(): void
    {
        NotificationRule::where('trigger', 'payment-reminder')->delete();

        $this->artisan('notifications:payment-reminders')
            ->expectsOutputToContain('No active payment-reminder rule found.')
            ->assertSuccessful();
    }

    public function test_sends_multiple_reminders_for_multiple_invoices(): void
    {
        Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 5000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(7),
        ]);

        Invoice::create([
            'client_id' => $this->client->id,
            'amount' => 10000,
            'status' => 'pending',
            'payment_status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        $this->artisan('notifications:payment-reminders')
            ->assertSuccessful();

        $this->assertEquals(2, NotificationModel::where('client_id', $this->client->id)->count());
    }
}
