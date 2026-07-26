<?php

namespace Tests\Unit;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\Channels\InAppDatabaseChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as BaseNotification;
use Tests\TestCase;

class InAppDatabaseChannelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'editor']);
    }

    public function test_creates_notification_row(): void
    {
        $notification = new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return [InAppDatabaseChannel::class];
            }

            public function toInApp(object $notifiable): array
            {
                return [
                    'text' => 'Test in-app message',
                    'type' => 'info',
                    'link' => '/tasks',
                ];
            }
        };

        $this->user->notify($notification);

        $this->assertDatabaseHas('notifications', [
            'text' => 'Test in-app message',
            'type' => 'info',
            'link' => '/tasks',
            'for_role' => 'user',
            'user_id' => $this->user->id,
            'read' => false,
        ]);
    }

    public function test_defaults_type_to_info(): void
    {
        $notification = new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return [InAppDatabaseChannel::class];
            }

            public function toInApp(object $notifiable): array
            {
                return [
                    'text' => 'No type specified',
                ];
            }
        };

        $this->user->notify($notification);

        $this->assertDatabaseHas('notifications', [
            'text' => 'No type specified',
            'type' => 'info',
        ]);
    }

    public function test_null_link_is_stored(): void
    {
        $notification = new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return [InAppDatabaseChannel::class];
            }

            public function toInApp(object $notifiable): array
            {
                return [
                    'text' => 'No link',
                    'type' => 'success',
                ];
            }
        };

        $this->user->notify($notification);

        $this->assertDatabaseHas('notifications', [
            'text' => 'No link',
            'link' => null,
        ]);
    }

    public function test_skips_when_text_is_empty(): void
    {
        $notification = new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return [InAppDatabaseChannel::class];
            }

            public function toInApp(object $notifiable): array
            {
                return [
                    'text' => '',
                    'type' => 'info',
                ];
            }
        };

        $this->user->notify($notification);

        $this->assertEquals(0, Notification::count());
    }

    public function test_skips_when_no_to_in_app_method(): void
    {
        $notification = new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return [InAppDatabaseChannel::class];
            }
        };

        $this->user->notify($notification);

        $this->assertEquals(0, Notification::count());
    }

    public function test_enforces_50_row_cap(): void
    {
        // Pre-fill 50 notifications
        for ($i = 0; $i < 50; $i++) {
            Notification::create([
                'text' => "Existing notification {$i}",
                'type' => 'info',
                'for_role' => 'all',
                'read' => false,
            ]);
        }

        $notification = new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return [InAppDatabaseChannel::class];
            }

            public function toInApp(object $notifiable): array
            {
                return [
                    'text' => 'New notification pushing over cap',
                    'type' => 'info',
                ];
            }
        };

        $this->user->notify($notification);

        $this->assertEquals(50, Notification::count());
        $this->assertDatabaseHas('notifications', [
            'text' => 'New notification pushing over cap',
        ]);
    }

    public function test_sets_user_id_on_notification(): void
    {
        $notification = new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return [InAppDatabaseChannel::class];
            }

            public function toInApp(object $notifiable): array
            {
                return [
                    'text' => 'Targeted notification',
                    'type' => 'info',
                ];
            }
        };

        $this->user->notify($notification);

        $row = Notification::where('text', 'Targeted notification')->first();
        $this->assertNotNull($row);
        $this->assertEquals($this->user->id, $row->user_id);
    }
}
