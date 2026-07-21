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
            ['name' => 'Super Admin',    'email' => 'super@madhyam.com',   'password' => 'admin123', 'role' => 'super-admin',  'department' => 'Management', 'phone' => '+977-9800000000', 'join_date' => '2025-01-01'],
            ['name' => 'Admin User',     'email' => 'admin@madhyam.com',   'password' => 'pass123',  'role' => 'admin',        'department' => 'Management', 'phone' => '+977-9800000099', 'join_date' => '2025-02-01'],
            ['name' => 'Rajesh Sharma',  'email' => 'rajesh@madhyam.com',  'password' => 'pass123',  'role' => 'manager',      'department' => 'Marketing',  'phone' => '+977-9800000001', 'join_date' => '2025-03-01'],
            ['name' => 'Sita Poudel',    'email' => 'sita@madhyam.com',    'password' => 'pass123',  'role' => 'videographer', 'department' => 'Production', 'phone' => '+977-9800000002', 'join_date' => '2025-04-15'],
            ['name' => 'Anil Thapa',     'email' => 'anil@madhyam.com',    'password' => 'pass123',  'role' => 'editor',       'department' => 'Production', 'phone' => '+977-9800000003', 'join_date' => '2025-05-01'],
            ['name' => 'Priya Gurung',   'email' => 'priya@madhyam.com',   'password' => 'pass123',  'role' => 'designer',     'department' => 'Creative',   'phone' => '+977-9800000004', 'join_date' => '2025-06-01'],
            ['name' => 'Bikash Rai',     'email' => 'bikash@madhyam.com',  'password' => 'pass123',  'role' => 'social-media', 'department' => 'Marketing',  'phone' => '+977-9800000005', 'join_date' => '2025-07-01'],
            ['name' => 'Karma Lama',     'email' => 'karma@madhyam.com',   'password' => 'pass123',  'role' => 'copywriter',   'department' => 'Marketing',  'phone' => '+977-9800000006', 'join_date' => '2025-08-01'],
        ];

        foreach ($accounts as $a) {
            DB::table('users')->insert([
                'name' => $a['name'],
                'email' => $a['email'],
                'password' => Hash::make($a['password']),
                'role' => $a['role'],
                'department_id' => $dept[$a['department']] ?? null,
                'phone' => $a['phone'],
                'join_date' => $a['join_date'],
                'status' => 'active',
                'avatar' => null,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
