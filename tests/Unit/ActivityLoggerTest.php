<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLoggerTest extends TestCase
{
    use RefreshDatabase;

    protected ActivityLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ActivityLogger;
    }

    public function test_record_creates_log(): void
    {
        $user = User::create(['name' => 'Test User', 'email' => 'test@test.com', 'password' => 'password', 'role' => 'editor']);

        $log = $this->logger->record($user, 'Test activity');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'user' => 'Test User',
            'text' => 'Test activity',
        ]);
    }

    public function test_record_with_null_user(): void
    {
        $log = $this->logger->record(null, 'System activity');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => null,
            'text' => 'System activity',
        ]);
    }

    public function test_logs_capped_at_100(): void
    {
        // Create 100 existing logs
        for ($i = 0; $i < 100; $i++) {
            ActivityLog::create([
                'user_id' => null,
                'user' => 'System',
                'text' => "Old log {$i}",
                'time' => now()->subMinutes(100 - $i),
            ]);
        }

        // Add one more — should trigger trim
        $this->logger->record(null, 'New log');

        $this->assertEquals(100, ActivityLog::count());
        $this->assertDatabaseHas('activity_logs', ['text' => 'New log']);
        $this->assertDatabaseMissing('activity_logs', ['text' => 'Old log 0']);
    }

    public function test_record_returns_activity_log_model(): void
    {
        $user = User::create(['name' => 'Test User', 'email' => 'test@test.com', 'password' => 'password', 'role' => 'editor']);

        $log = $this->logger->record($user, 'Test');

        $this->assertInstanceOf(ActivityLog::class, $log);
        $this->assertEquals('Test', $log->text);
    }
}
