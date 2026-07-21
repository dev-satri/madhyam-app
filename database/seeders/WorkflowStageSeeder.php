<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkflowStageSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['key' => 'idea',      'name' => 'Idea',      'color' => '#94a3b8', 'order' => 0],
            ['key' => 'scripting', 'name' => 'Scripting', 'color' => '#3b82f6', 'order' => 1],
            ['key' => 'shooting',  'name' => 'Shooting',  'color' => '#f59e0b', 'order' => 2],
            ['key' => 'editing',   'name' => 'Editing',   'color' => '#a855f7', 'order' => 3],
            ['key' => 'review',    'name' => 'Review',    'color' => '#06b6d4', 'order' => 4],
            ['key' => 'published', 'name' => 'Published', 'color' => '#10b981', 'order' => 5],
        ];
        foreach ($rows as $r) {
            DB::table('workflow_stages')->insert(array_merge($r, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
