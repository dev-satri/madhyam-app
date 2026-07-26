<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Notifications\PackageUsageLimitReachedNotification;
use App\Notifications\PackageUsageWarningNotification;
use App\Services\PackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers the per-deliverable-type usage tracking that was layered on top of
 * the aggregate content/workflow/storage limits: each package can declare
 * limits per type (reel, post, story…) and PackageService::recordContent()
 * bumps a JSON counter per type, firing warning/limit notifications at
 * 80%/100% for that specific type.
 */
class PackageDeliverableUsageTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('settings')->insert([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('packages')->insert([
            'slug' => 'growth',
            'name' => 'Growth Plan',
            'monthly_amount' => 15000,
            'content_limit' => 100,
            'workflow_limit' => 100,
            'storage_limit_mb' => 10240,
            'revision_limit' => 5,
            'priority_support' => false,
            'status' => 'active',
            'deliverable_limits' => json_encode([
                ['type' => 'reel', 'limit' => 5],
                ['type' => 'post', 'limit' => 10],
                ['type' => 'story', 'limit' => 20],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->client = Client::create([
            'name' => 'Test Client',
            'email' => 'test@client.com',
            'package' => 'growth',
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

    public function test_record_content_accumulates_per_type(): void
    {
        Notification::fake();

        PackageService::recordContent($this->client->id, 'created', 'reel');
        PackageService::recordContent($this->client->id, 'created', 'reel');
        PackageService::recordContent($this->client->id, 'created', 'post');

        $usage = PackageService::getUsage($this->client->id);
        $this->assertSame(['reel' => 2, 'post' => 1], $usage['deliverable_counts']);
        $this->assertSame(3, $usage['content_created']);
    }

    public function test_get_usage_with_status_returns_per_type_percents(): void
    {
        Notification::fake();

        // reel: 5 limit — bump 4 → 80%
        for ($i = 0; $i < 4; $i++) {
            PackageService::recordContent($this->client->id, 'created', 'reel');
        }
        // post: 10 limit — bump 3 → 30%
        for ($i = 0; $i < 3; $i++) {
            PackageService::recordContent($this->client->id, 'created', 'post');
        }

        $status = PackageService::getUsageWithStatus($this->client->id);

        $byType = [];
        foreach ($status['deliverables'] as $row) {
            $byType[$row['type']] = $row;
        }

        $this->assertSame(4, $byType['reel']['used']);
        $this->assertSame(5, $byType['reel']['limit']);
        $this->assertSame(80, $byType['reel']['percent']);
        $this->assertFalse($byType['reel']['over']);

        $this->assertSame(3, $byType['post']['used']);
        $this->assertSame(30, $byType['post']['percent']);

        $this->assertSame(0, $byType['story']['used']);
        $this->assertSame(0, $byType['story']['percent']);
    }

    public function test_warning_fires_when_type_crosses_80_percent(): void
    {
        Notification::fake();

        // reel limit is 5 — bump 4 → 80%
        for ($i = 0; $i < 4; $i++) {
            PackageService::recordContent($this->client->id, 'created', 'reel');
        }

        Notification::assertSentTo(
            $this->account,
            PackageUsageWarningNotification::class,
            fn ($notification) => ($notification->usage['category'] ?? null) === 'reel'
        );
    }

    public function test_limit_notification_fires_when_type_hits_100_percent(): void
    {
        Notification::fake();

        // reel limit is 5 — bump 5 → 100%
        for ($i = 0; $i < 5; $i++) {
            PackageService::recordContent($this->client->id, 'created', 'reel');
        }

        Notification::assertSentTo(
            $this->account,
            PackageUsageLimitReachedNotification::class,
            fn ($notification) => ($notification->usage['category'] ?? null) === 'reel'
        );
    }

    public function test_no_per_type_notification_below_80_percent(): void
    {
        Notification::fake();

        // reel limit is 5 — bump 3 → 60%
        for ($i = 0; $i < 3; $i++) {
            PackageService::recordContent($this->client->id, 'created', 'reel');
        }

        Notification::assertNotSentTo(
            $this->account,
            PackageUsageWarningNotification::class,
            fn ($notification) => ($notification->usage['category'] ?? null) === 'reel'
        );
    }

    public function test_build_deliverable_breakdown_shape(): void
    {
        $rows = PackageService::buildDeliverableBreakdown(
            limits: [
                ['type' => 'reel', 'limit' => 8],
                ['type' => 'post', 'limit' => 4],
            ],
            counts: ['reel' => 7, 'post' => 0],
        );

        $this->assertCount(2, $rows);

        $this->assertSame('reel', $rows[0]['type']);
        $this->assertSame(7, $rows[0]['used']);
        $this->assertSame(8, $rows[0]['limit']);
        $this->assertSame(88, $rows[0]['percent']);
        $this->assertFalse($rows[0]['over']);

        $this->assertSame('post', $rows[1]['type']);
        $this->assertSame(0, $rows[1]['used']);
        $this->assertSame(0, $rows[1]['percent']);
    }
}
