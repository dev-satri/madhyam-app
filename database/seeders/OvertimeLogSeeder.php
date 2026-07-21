<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OvertimeLogSeeder extends Seeder
{
    public function run(): void
    {
        $u = fn (string $email) => DB::table('users')->where('email', $email)->value('id');

        $rows = [
            ['member_id' => $u('rajesh@madhyam.com'), 'date' => '2026-07-08', 'hours' => 3.0, 'description' => 'Client deadline - Himalayan Coffee video edit', 'approved' => true,  'rate' => 500],
            ['member_id' => $u('sita@madhyam.com'),   'date' => '2026-07-10', 'hours' => 2.5, 'description' => 'Shoot extended - GreenLeaf product',           'approved' => true,  'rate' => 450],
            ['member_id' => $u('bikash@madhyam.com'), 'date' => '2026-07-12', 'hours' => 2.0, 'description' => 'Social media emergency post',                  'approved' => false, 'rate' => 450],
        ];

        foreach ($rows as $r) {
            DB::table('overtime_logs')->insert(array_merge($r, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
