<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test in-app notifications — 4 rows covering role broadcast,
 * role-specific, and client-scoped notifications.
 */
class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->value('id');

        $rows = [
            [
                'text' => 'New approval submitted: Himalayan Coffee — Festival Blend Post',
                'type' => 'approval',
                'read' => false,
                'link' => 'approvals',
                'for_role' => 'all',
                'client_id' => null,
                'user_id' => null,
            ],
            [
                'text' => 'Deadline tomorrow: Ads Cutdown export',
                'type' => 'deadline',
                'read' => false,
                'link' => 'tasks',
                'for_role' => 'all',
                'client_id' => null,
                'user_id' => null,
            ],
            [
                'text' => 'Task assigned to you: Colour grade Barista Series footage',
                'type' => 'task',
                'read' => false,
                'link' => 'tasks',
                'for_role' => 'editor',
                'client_id' => null,
                'user_id' => $editor,
            ],
            [
                'text' => 'Your Diwali Campaign Reel is ready for approval',
                'type' => 'approval',
                'read' => false,
                'link' => 'approvals',
                'for_role' => 'client',
                'client_id' => $c1,
                'user_id' => null,
            ],
        ];

        foreach ($rows as $r) {
            DB::table('notifications')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command?->info('  ✓ Notifications: 4 (role broadcast + role-specific + client-scoped)');
    }
}
