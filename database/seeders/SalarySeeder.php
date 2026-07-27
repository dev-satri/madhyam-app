<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test payslips — one row per staff member for July 2026.
 *
 * 3 marked paid, 1 pending — so the Salary page has both branches
 * (approved/paid rows and outstanding rows) to test.
 */
class SalarySeeder extends Seeder
{
    public function run(): void
    {
        $u = fn (string $email) => DB::table('users')->where('email', $email)->value('id');

        $rows = [
            ['email' => 'superadmin@madhyam.com',   'base' => 60000, 'ot' => 0,    'bonus' => 5000, 'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 65000, 'status' => 'paid'],
            ['email' => 'admin@madhyam.com',        'base' => 45000, 'ot' => 0,    'bonus' => 3000, 'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 48000, 'status' => 'paid'],
            ['email' => 'staff.editor@madhyam.com', 'base' => 30000, 'ot' => 1500, 'bonus' => 0,    'deduct' => 1364, 'paid_leaves' => 1, 'days' => 21, 'net' => 30136, 'status' => 'paid'],
            ['email' => 'staff.video@madhyam.com',  'base' => 30000, 'ot' => 1000, 'bonus' => 0,    'deduct' => 0,    'paid_leaves' => 0, 'days' => 22, 'net' => 31000, 'status' => 'pending'],
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

        $this->command?->info('  ✓ Salaries: 4 payslips for July 2026 (3 paid, 1 pending)');
    }
}
