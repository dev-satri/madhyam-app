<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ComplaintReplySeeder extends Seeder
{
    public function run(): void
    {
        $users = DB::table('users')->pluck('id', 'email');

        $replyMap = [
            'Late delivery of reel' => [
                'user_email' => 'super@madhyam.com',
                'user_name' => 'Super Admin',
                'text' => 'We apologize. The team is working on preventing this.',
                'days_ago' => 1,
            ],
            'Caption tone mismatch' => [
                'user_email' => 'karma@madhyam.com',
                'user_name' => 'Karma Lama',
                'text' => 'Updated the caption style guide. Will follow casual tone going forward.',
                'days_ago' => 2,
            ],
        ];

        $complaints = DB::table('complaints')->select('id', 'title')->get();

        foreach ($complaints as $complaint) {
            $reply = $replyMap[$complaint->title] ?? null;
            if (! $reply) {
                continue;
            }

            $exists = DB::table('complaint_replies')
                ->where('complaint_id', $complaint->id)
                ->where('text', $reply['text'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('complaint_replies')->insert([
                'complaint_id' => $complaint->id,
                'user_id' => $users[$reply['user_email']] ?? null,
                'user_name' => $reply['user_name'],
                'text' => $reply['text'],
                'created_at' => now()->subDays($reply['days_ago']),
                'updated_at' => now()->subDays($reply['days_ago']),
            ]);
        }
    }
}
