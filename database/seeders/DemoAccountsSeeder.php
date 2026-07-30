<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Final-test staff accounts.
 *
 * Exactly 4 accounts covering the roles needed for end-to-end manual testing:
 *   1. super-admin  — full oversight, owns the agency (us)
 *   2. admin        — main project admin under us
 *   3. editor       — creative staff (post-production)
 *   4. videographer — production staff (shoots)
 *
 * Idempotent via updateOrInsert on email so re-running the seeder never
 * duplicates accounts. Passwords are simple + memorable for the manual
 * test cycle — see docs/FINAL_TEST_PLAN.md for the credentials block.
 */
class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $dept = DB::table('departments')->pluck('id', 'name');

        $accounts = [
            [
                'name' => 'Super Admin',
                'email' => 'superadmin@madhyam.com',
                'password' => 'SuperAdmin@123',
                'role' => 'super-admin',
                'department' => 'Management',
                'phone' => '+977-9800000001',
                'join_date' => '2026-01-01',
            ],
            [
                'name' => 'Project Admin',
                'email' => 'admin@madhyam.com',
                'password' => 'Admin@123',
                'role' => 'admin',
                'department' => 'Management',
                'phone' => '+977-9800000002',
                'join_date' => '2026-01-15',
            ],
        ];

        foreach ($accounts as $a) {
            DB::table('users')->updateOrInsert(
                ['email' => $a['email']],
                [
                    'name' => $a['name'],
                    'password' => Hash::make($a['password']),
                    'role' => $a['role'],
                    'department_id' => $dept[$a['department']] ?? null,
                    'phone' => $a['phone'],
                    'join_date' => $a['join_date'],
                    'status' => 'active',
                    'avatar' => null,
                    'email_verified_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->command?->info('  ✓ Staff accounts: 4 (super-admin, admin, editor, videographer)');
    }
}
