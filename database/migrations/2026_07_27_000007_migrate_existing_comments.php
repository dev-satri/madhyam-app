<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('task_comments')->orderBy('id')->each(function ($comment) use ($now) {
            DB::table('comments')->insert([
                'commentable_type' => 'App\\Models\\Task',
                'commentable_id' => $comment->task_id,
                'user_id' => $comment->user_id,
                'body' => e($comment->text),
                'attachments' => null,
                'is_system' => false,
                'created_at' => $comment->created_at ?? $now,
                'updated_at' => $comment->updated_at ?? $now,
            ]);
        });

        DB::table('approval_comments')->orderBy('id')->each(function ($comment) use ($now) {
            DB::table('comments')->insert([
                'commentable_type' => 'App\\Models\\Approval',
                'commentable_id' => $comment->approval_id,
                'user_id' => $comment->user_id,
                'body' => e($comment->text),
                'attachments' => null,
                'is_system' => (bool) ($comment->is_system ?? false),
                'created_at' => $comment->created_at ?? $now,
                'updated_at' => $comment->updated_at ?? $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('comments')->truncate();
    }
};
