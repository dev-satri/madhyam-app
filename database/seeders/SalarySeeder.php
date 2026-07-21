<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalarySeeder extends Seeder
{
    public function run(): void
    {
        $u = fn (string $email) => DB::table('users')->where('email', $email)->value('id');

        $rows = [
            ['email' => 'super@madhyam.com',  'base' => 50000, 'ot' => 0,    'bonus' => 5000, 'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 55000, 'status' => 'paid'],
            ['email' => 'rajesh@madhyam.com', 'base' => 35000, 'ot' => 3500, 'bonus' => 2000, 'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 40500, 'status' => 'paid'],
            ['email' => 'sita@madhyam.com',   'base' => 30000, 'ot' => 2000, 'bonus' => 0,    'deduct' => 1364, 'paid_leaves' => 1, 'days' => 21, 'net' => 30636, 'status' => 'paid'],
            ['email' => 'anil@madhyam.com',   'base' => 28000, 'ot' => 0,    'bonus' => 0,    'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 28000, 'status' => 'pending'],
            ['email' => 'priya@madhyam.com',  'base' => 28000, 'ot' => 0,    'bonus' => 1000, 'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 29000, 'status' => 'pending'],
            ['email' => 'bikash@madhyam.com', 'base' => 30000, 'ot' => 1500, 'bonus' => 0,    'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 31500, 'status' => 'pending'],
            ['email' => 'karma@madhyam.com',  'base' => 28000, 'ot' => 0,    'bonus' => 500,  'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 28500, 'status' => 'pending'],
        ];

        foreach ($rows as $r) {
            $memberId = $u($r['email']);
            if (! $memberId) {
                continue;
            }
            DB::table('salaries')->insert([
                'member_id' => $memberId,
                'month' => 7,
                'year' => 2026,
                'base_salary' => $r['base'],
                'overtime_pay' => $r['ot'],
                'bonus' => $r['bonus'],
                'leave_deduction' => $r['deduct'],
                'paid_leaves' => $r['paid_leaves'],
                'unpaid_leaves' => 0,
                'total_work_days' => $r['days'],
                'net_salary' => $r['net'],
                'status' => $r['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
