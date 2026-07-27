<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test overtime logs.
 *
 * 3 entries covering both staff members and both approval states
 * so the OT page has data on both an approver (admin) and requester
 * (staff) view.
 */
class OvertimeLogSeeder extends Seeder
{
    public function run(): void
    {
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->value('id');
        $videographer = DB::table('users')->where('email', 'staff.video@madhyam.com')->value('id');

        $rows = [
            ['member_id' => $editor,       'date' => now()->subDays(4)->toDateString(),  'hours' => 3.0, 'description' => 'Client deadline — Himalayan Coffee reel edit',      'approved' => true,  'rate' => 500, 'paid' => true],
            ['member_id' => $videographer, 'date' => now()->subDays(6)->toDateString(),  'hours' => 2.5, 'description' => 'Shoot extended — Trek Nepal guide testimonial',    'approved' => true,  'rate' => 450, 'paid' => true],
            ['member_id' => $videographer, 'date' => now()->subDays(2)->toDateString(),  'hours' => 1.5, 'description' => 'Emergency social post shoot',                       'approved' => false, 'rate' => 450, 'paid' => false],
        ];

        foreach ($rows as $r) {
            DB::table('overtime_logs')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command?->info('  ✓ Overtime: 3 logs (2 approved, 1 pending)');
    }
}
