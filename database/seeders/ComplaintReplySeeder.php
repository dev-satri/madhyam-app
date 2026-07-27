<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test complaint replies — one admin reply per complaint.
 */
class ComplaintReplySeeder extends Seeder
{
    public function run(): void
    {
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->first();
        if (! $admin) {
            return;
        }

        $replies = [
            'Reel delivered 2 days late' => 'Thank you for flagging this. We are auditing the pipeline and will share a fix plan by end of week.',
            'Caption tone too formal'    => 'Updated the caption style guide for the copywriter. All future captions will use the adventure tone.',
        ];

        foreach ($replies as $title => $text) {
            $complaintId = DB::table('complaints')->where('title', $title)->value('id');
            if (! $complaintId) {
                continue;
            }

            DB::table('complaint_replies')->insert([
                'complaint_id' => $complaintId,
                'user_id' => $admin->id,
                'user_name' => $admin->name,
                'text' => $text,
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ]);
        }

        $this->command?->info('  ✓ Complaint replies: 1 per complaint');
    }
}
