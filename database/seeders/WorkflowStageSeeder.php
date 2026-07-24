<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkflowStageSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            ['key' => 'todo', 'name' => 'To Do', 'order' => 1, 'color' => '#6366f1'],
            ['key' => 'in-progress', 'name' => 'In Progress', 'order' => 2, 'color' => '#f59e0b'],
            ['key' => 'scripting', 'name' => 'Scripting', 'order' => 3, 'color' => '#8b5cf6'],
            ['key' => 'review', 'name' => 'Review', 'order' => 4, 'color' => '#3b82f6'],
            ['key' => 'revision', 'name' => 'Revision', 'order' => 5, 'color' => '#ef4444'],
            ['key' => 'ready-for-production', 'name' => 'Ready for Production', 'order' => 6, 'color' => '#0ea5e9'],
            ['key' => 'published', 'name' => 'Published', 'order' => 7, 'color' => '#22c55e'],
        ];

        foreach ($stages as $stage) {
            DB::table('workflow_stages')->updateOrInsert(
                ['key' => $stage['key']],
                $stage + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
