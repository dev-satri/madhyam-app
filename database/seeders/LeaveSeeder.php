<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeaveSeeder extends Seeder
{
    public function run(): void
    {
        $u = fn (string $email) => DB::table('users')->where('email', $email)->value('id');

        $rows = [
            ['member_id' => $u('sita@madhyam.com'),  'type' => 'sick',   'start_date' => '2026-07-10', 'end_date' => '2026-07-11', 'reason' => 'Not feeling well',  'status' => 'approved', 'approved_by' => $u('super@madhyam.com')],
            ['member_id' => $u('anil@madhyam.com'),  'type' => 'casual', 'start_date' => '2026-07-20', 'end_date' => '2026-07-20', 'reason' => 'Personal work',     'status' => 'pending',  'approved_by' => null],
            ['member_id' => $u('priya@madhyam.com'), 'type' => 'annual', 'start_date' => '2026-08-01', 'end_date' => '2026-08-05', 'reason' => 'Family vacation',   'status' => 'pending',  'approved_by' => null],
        ];

        foreach ($rows as $r) {
            DB::table('leaves')->insert(array_merge($r, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
