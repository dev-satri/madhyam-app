<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test activity log — 8 recent entries so the admin activity
 * feed on the dashboard has data.
 */
class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = DB::table('users')->where('email', 'superadmin@madhyam.com')->first();
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->first();
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->first();
        $videographer = DB::table('users')->where('email', 'staff.video@madhyam.com')->first();

        $activities = [
            [$editor,       'Logged in.',                                                                       5],
            [$admin,        'Created invoice for Himalayan Coffee Co. — Diwali Campaign.',                     30],
            [$editor,       'Uploaded Farm-Visit-Raw.mp4.',                                                    60],
            [$videographer, 'Marked shoot task "Shoot trek guide testimonial" as completed.',                  120],
            [$admin,        'Approved submission "Himalayan Coffee — Festival Blend Post".',                  180],
            [$superAdmin,   'Approved July payroll batch.',                                                   240],
            [$admin,        'Resolved complaint "Caption tone too formal".',                                  360],
            [$superAdmin,   'Exported monthly performance report.',                                           480],
        ];

        foreach ($activities as [$user, $text, $minutesAgo]) {
            if (! $user) {
                continue;
            }
            DB::table('activity_logs')->insert([
                'user_id' => $user->id,
                'user' => $user->name,
                'text' => $text,
                'time' => now()->subMinutes($minutesAgo),
            ]);
        }

        $this->command?->info('  ✓ Activity log: 8 recent entries');
    }
}
