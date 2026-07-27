<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test approval comments.
 *
 * One human comment on every approval + one system-generated comment
 * on the rejected approval so both branches of the comments UI have
 * something to render.
 */
class ApprovalCommentSeeder extends Seeder
{
    public function run(): void
    {
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->first();
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->first();

        $approvals = DB::table('approvals')->get();
        foreach ($approvals as $approval) {
            DB::table('approval_comments')->insert([
                'approval_id' => $approval->id,
                'user_id' => $admin->id,
                'user_name' => $admin->name,
                'text' => match ($approval->status) {
                    'approved' => 'Looks great — approved and moving forward.',
                    'rejected' => 'Sending this back for revision — see rejection reason above.',
                    default => 'Reviewing now, will post feedback shortly.',
                },
                'is_system' => false,
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ]);

            if ($approval->status === 'rejected') {
                DB::table('approval_comments')->insert([
                    'approval_id' => $approval->id,
                    'user_id' => $editor->id,
                    'user_name' => 'System',
                    'text' => 'System: status changed to rejected — revision required.',
                    'is_system' => true,
                    'created_at' => now()->subHours(20),
                    'updated_at' => now()->subHours(20),
                ]);
            }
        }
    }
}
