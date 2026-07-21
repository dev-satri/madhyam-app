<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'text' => 'New approval submitted: Reel Draft - Himalayan',
                'type' => 'approval',
                'read' => false,
                'link' => 'approvals',
                'for_role' => 'all',
            ],
            [
                'text' => 'Deadline tomorrow: Brand Video Q3',
                'type' => 'deadline',
                'read' => false,
                'link' => 'workflow',
                'for_role' => 'all',
            ],
            [
                'text' => 'Task assigned: Edit intro sequence',
                'type' => 'task',
                'read' => true,
                'link' => 'tasks',
                'for_role' => 'editor',
            ],
        ];

        foreach ($rows as $r) {
            DB::table('notifications')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
