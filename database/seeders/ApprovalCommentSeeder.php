<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ApprovalCommentSeeder extends Seeder
{
    public function run(): void
    {
        $approvals = DB::table('approvals')->select('id', 'title', 'status')->get();
        $users = DB::table('users')->where('role', '!=', 'super-admin')->select('id', 'name')->get();

        $commentMap = [
            'Reel Draft - Himalayan' => [['text' => 'Looks good, minor audio sync issue.', 'is_system' => false]],
            'Post Carousel - Trek Nepal' => [['text' => 'Carousel approved for posting.', 'is_system' => false]],
            'Video Edit - GreenLeaf' => [['text' => 'Needs color correction on frame 120.', 'is_system' => false], ['text' => 'Revision requested — adjust pacing.', 'is_system' => false]],
            'Story Set - KTM Bites' => [['text' => 'Story format matches brand guidelines.', 'is_system' => false]],
            'Blog Draft - Resort' => [['text' => 'System: Status changed to revision.', 'is_system' => true]],
        ];

        foreach ($approvals as $approval) {
            $comments = $commentMap[$approval->title] ?? [['text' => 'Please review this submission.', 'is_system' => false]];

            foreach ($comments as $i => $comment) {
                $user = $users[$i % count($users)];
                DB::table('approval_comments')->insert([
                    'approval_id' => $approval->id,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'text' => $comment['text'],
                    'is_system' => $comment['is_system'],
                    'created_at' => now()->subDays(random_int(0, 7)),
                    'updated_at' => now()->subDays(random_int(0, 7)),
                ]);
            }
        }
    }
}
