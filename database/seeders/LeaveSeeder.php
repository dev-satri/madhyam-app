<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test leave requests.
 *
 * 3 requests covering all types (sick / casual / annual) and both
 * pending + approved so the Leaves page has both approval and audit
 * branches to test.
 */
class LeaveSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = DB::table('users')->where('email', 'superadmin@madhyam.com')->value('id');
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->value('id');
        $videographer = DB::table('users')->where('email', 'staff.video@madhyam.com')->value('id');

        $rows = [
            ['member_id' => $editor,       'type' => 'sick',   'start_date' => now()->subDays(3)->toDateString(),  'end_date' => now()->subDays(2)->toDateString(),  'reason' => 'Fever + flu',            'status' => 'approved', 'approved_by' => $admin],
            ['member_id' => $videographer, 'type' => 'casual', 'start_date' => now()->addDays(4)->toDateString(),  'end_date' => now()->addDays(4)->toDateString(),  'reason' => 'Personal work',          'status' => 'pending',  'approved_by' => null],
            ['member_id' => $admin,        'type' => 'annual', 'start_date' => now()->addDays(14)->toDateString(), 'end_date' => now()->addDays(18)->toDateString(), 'reason' => 'Family vacation',        'status' => 'approved', 'approved_by' => $superAdmin],
        ];

        foreach ($rows as $r) {
            DB::table('leaves')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command?->info('  ✓ Leaves: 3 (sick approved, casual pending, annual approved)');
    }
}
