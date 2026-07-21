<?php

namespace Tests\Unit;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService;
    }

    public function test_send_notification_creates_record(): void
    {
        $notification = $this->service->sendNotification(
            text: 'Test notification',
            type: 'info',
            link: '/dashboard',
            forRole: 'all',
        );

        $this->assertDatabaseHas('notifications', [
            'text' => 'Test notification',
            'type' => 'info',
            'link' => '/dashboard',
            'for_role' => 'all',
            'read' => false,
        ]);
    }

    public function test_notifications_capped_at_50(): void
    {
        // Create 50 existing notifications
        for ($i = 0; $i < 50; $i++) {
            Notification::create([
                'text' => "Old notification {$i}",
                'type' => 'info',
                'for_role' => 'all',
                'read' => false,
                'created_at' => now()->subMinutes(60 - $i),
            ]);
        }

        // Send one more — should trigger trim
        $this->service->sendNotification(text: 'New notification', type: 'info');

        $this->assertEquals(50, Notification::count());
        $this->assertDatabaseHas('notifications', ['text' => 'New notification']);
    }

    public function test_mark_read(): void
    {
        $notification = Notification::create([
            'text' => 'Unread notification',
            'type' => 'info',
            'for_role' => 'all',
            'read' => false,
        ]);

        $this->service->markRead($notification->id);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'read' => true,
        ]);
    }

    public function test_mark_all_read(): void
    {
        Notification::create(['text' => 'One', 'type' => 'info', 'for_role' => 'all', 'read' => false]);
        Notification::create(['text' => 'Two', 'type' => 'info', 'for_role' => 'all', 'read' => false]);
        Notification::create(['text' => 'Three', 'type' => 'info', 'for_role' => 'all', 'read' => true]);

        $this->service->markAllRead();

        $this->assertEquals(0, Notification::where('read', false)->count());
    }

    public function test_mark_all_read_for_role(): void
    {
        Notification::create(['text' => 'Staff', 'type' => 'info', 'for_role' => 'admin', 'read' => false]);
        Notification::create(['text' => 'Client', 'type' => 'info', 'for_role' => 'client', 'read' => false]);

        $this->service->markAllRead('admin');

        $this->assertDatabaseHas('notifications', ['text' => 'Staff', 'read' => true]);
        $this->assertDatabaseHas('notifications', ['text' => 'Client', 'read' => false]);
    }

    public function test_notification_types(): void
    {
        $types = ['info', 'success', 'warning', 'error'];
        foreach ($types as $type) {
            $this->service->sendNotification(text: "Test {$type}", type: $type);
            $this->assertDatabaseHas('notifications', ['text' => "Test {$type}", 'type' => $type]);
        }
    }
}
