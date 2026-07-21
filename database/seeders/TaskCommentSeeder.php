<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaskCommentSeeder extends Seeder
{
    public function run(): void
    {
        $tasks = DB::table('tasks')->select('id', 'title')->get();
        $users = DB::table('users')->where('role', '!=', 'super-admin')->select('id', 'name')->get();

        $comments = [
            'Edit intro sequence' => ['Looks great, minor color tweak needed.', 'Approved, shipping now.'],
            'Color grade footage' => ['Can we warm up the highlights?', 'Done — warmer grade applied.'],
            'Write caption draft' => ['Captions look solid.', 'Updated with hashtags.'],
            'Design thumbnail' => ['Thumbnail is eye-catching.', 'Revised contrast per feedback.'],
            'Schedule posts' => ['Posts queued for next week.'],
            'Shoot BTS photos' => ['BTS photos uploaded to drive.'],
            'Export final cut' => ['Export rendered in 4K.'],
        ];

        foreach ($tasks as $task) {
            $texts = $comments[$task->title] ?? null;
            if (! $texts) {
                $texts = ['Initial comment on this task.'];
            }

            foreach ($texts as $i => $text) {
                $user = $users[$i % count($users)];
                DB::table('task_comments')->insert([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'text' => $text,
                    'created_at' => now()->subDays(random_int(0, 5)),
                    'updated_at' => now()->subDays(random_int(0, 5)),
                ]);
            }
        }
    }
}
