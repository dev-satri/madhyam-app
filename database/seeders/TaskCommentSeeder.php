<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test task comments — 1-2 comments on the first four tasks
 * so the comments UI has data on both editor and videographer profiles.
 */
class TaskCommentSeeder extends Seeder
{
    public function run(): void
    {
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->value('id');
        $videographer = DB::table('users')->where('email', 'staff.video@madhyam.com')->value('id');

        $comments = [
            'Edit Farm Visit intro sequence' => [
                [$admin, 'Please tighten the first 5 seconds — the hook is a bit slow.'],
                [$editor, 'Noted — cutting the establishing shot and jumping straight to the espresso pour.'],
            ],
            'Colour grade Barista Series footage' => [
                [$admin, 'Match the brand LUT — warmer highlights, deeper shadows.'],
            ],
            'Shoot BTS at coffee farm' => [
                [$admin, 'Remember to grab audio-only interview clips for reels.'],
                [$videographer, 'Will do — bringing the shotgun mic and Lav for backup.'],
            ],
            'Ads Cutdown — overdue export' => [
                [$admin, 'This is overdue — please export tonight and share the link.'],
            ],
        ];

        foreach ($comments as $title => $entries) {
            $taskId = DB::table('tasks')->where('title', $title)->value('id');
            if (! $taskId) {
                continue;
            }

            foreach ($entries as $idx => [$userId, $text]) {
                DB::table('task_comments')->insert([
                    'task_id' => $taskId,
                    'user_id' => $userId,
                    'text' => $text,
                    'created_at' => now()->subDays(2)->addMinutes($idx * 30),
                    'updated_at' => now()->subDays(2)->addMinutes($idx * 30),
                ]);
            }
        }
    }
}
