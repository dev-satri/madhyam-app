<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
                'phone' => '+977-9800000001',
                'join_date' => '2026-01-01',
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@madhyam.com',
                'password' => 'Admin@123',
                'role' => 'admin',
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
                    'department_id' => $dept['Management'] ?? null,
                    'phone' => $a['phone'],
                    'join_date' => $a['join_date'],
                    'status' => 'active',
                    'avatar' => null,
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command?->info('  ✓ Accounts seeded: superadmin + admin');
    }
}
