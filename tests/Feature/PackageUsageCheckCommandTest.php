<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Notification as NotificationModel;
use App\Models\NotificationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageUsageCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationRule::create([
            'name' => 'Package Usage Check',
            'trigger' => 'package-usage',
            'days' => 1,
            'active' => true,
        ]);

        DB::table('settings')->insert([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('packages')->insert([
            'slug' => 'basic',
            'name' => 'Basic',
            'monthly_amount' => 15000,
            'content_limit' => 8,
            'workflow_limit' => 5,
            'storage_limit_mb' => 512,
            'revision_limit' => 2,
            'priority_support' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
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

    public function test_sends_warning_at_80_percent_content(): void
    {
        // Basic package: content_limit = 8, set usage to 7 (87.5%)
        DB::table('package_usage')->insert([
            'client_id' => $this->client->id,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'content_created' => 7,
            'workflow_items' => 0,
            'storage_used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('notifications:package-usage')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
            'type' => 'warning',
        ]);
    }

    public function test_sends_limit_reached_at_100_percent(): void
    {
        // Basic package: content_limit = 8, set usage to 8 (100%)
        DB::table('package_usage')->insert([
            'client_id' => $this->client->id,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'content_created' => 8,
            'workflow_items' => 0,
            'storage_used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('notifications:package-usage')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
            'type' => 'error',
        ]);
    }

    public function test_sends_no_notification_below_80_percent(): void
    {
        // Basic package: content_limit = 8, set usage to 4 (50%)
        DB::table('package_usage')->insert([
            'client_id' => $this->client->id,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'content_created' => 4,
            'workflow_items' => 0,
            'storage_used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('notifications:package-usage')
            ->assertSuccessful();

        $this->assertEquals(0, NotificationModel::where('client_id', $this->client->id)->count());
    }

    public function test_sends_workflow_warning(): void
    {
        // Basic package: workflow_limit = 5, set usage to 5 (100%)
        DB::table('package_usage')->insert([
            'client_id' => $this->client->id,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'content_created' => 0,
            'workflow_items' => 5,
            'storage_used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('notifications:package-usage')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
            'type' => 'error',
        ]);

        $notification = NotificationModel::where('client_id', $this->client->id)->first();
        $this->assertStringContainsString('Workflow', $notification->text);
    }

    public function test_creates_in_app_notification(): void
    {
        // Basic package: content_limit = 8, set usage to 8 (100%)
        DB::table('package_usage')->insert([
            'client_id' => $this->client->id,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'content_created' => 8,
            'workflow_items' => 0,
            'storage_used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('notifications:package-usage')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'for_role' => 'client',
            'client_id' => $this->client->id,
            'type' => 'error',
        ]);
    }

    public function test_skips_inactive_clients(): void
    {
        $inactive = Client::create([
            'name' => 'Inactive Client',
            'email' => 'inactive@client.com',
            'package' => 'basic',
            'amount' => 15000,
            'status' => 'inactive',
        ]);

        DB::table('package_usage')->insert([
            'client_id' => $inactive->id,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'content_created' => 10,
            'workflow_items' => 0,
            'storage_used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('notifications:package-usage')
            ->assertSuccessful();

        $this->assertEquals(0, NotificationModel::count());
    }

    public function test_success_when_no_rule(): void
    {
        NotificationRule::where('trigger', 'package-usage')->delete();

        $this->artisan('notifications:package-usage')
            ->expectsOutputToContain('No active package-usage rule found.')
            ->assertSuccessful();
    }
}
