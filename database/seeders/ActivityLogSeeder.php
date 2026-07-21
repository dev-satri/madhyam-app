<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        $users = DB::table('users')->select('id', 'name', 'email')->get();

        $activities = [
            ['text' => 'Logged in to the system.', 'minutesAgo' => 5],
            ['text' => 'Created new task "Edit intro sequence".', 'minutesAgo' => 30],
            ['text' => 'Updated client Himalayan Coffee details.', 'minutesAgo' => 60],
            ['text' => 'Uploaded file "Banner-FB-Summer.jpg".', 'minutesAgo' => 120],
            ['text' => 'Approved submission "Post Carousel - Trek Nepal".', 'minutesAgo' => 180],
            ['text' => 'Generated invoice for July 2026.', 'minutesAgo' => 240],
            ['text' => 'Resolved complaint "Caption tone mismatch".', 'minutesAgo' => 360],
            ['text' => 'Exported monthly performance report.', 'minutesAgo' => 480],
            ['text' => 'Added new department "Creative".', 'minutesAgo' => 1440],
            ['text' => 'Changed workflow stage for "Video Edit - GreenLeaf".', 'minutesAgo' => 2880],
        ];

        foreach ($activities as $i => $activity) {
            $user = $users[$i % count($users)];
            DB::table('activity_logs')->insert([
                'user_id' => $user->id,
                'user' => $user->name,
                'text' => $activity['text'],
                'time' => now()->subMinutes($activity['minutesAgo']),
            ]);
        }
    }
}
