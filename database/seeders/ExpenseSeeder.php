<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $u = fn (string $email) => DB::table('users')->where('email', $email)->value('id');
        $c = fn (string $name) => DB::table('clients')->where('name', $name)->value('id');

        $super = $u('super@madhyam.com');
        $manager = $u('rajesh@madhyam.com');
        $videographer = $u('sita@madhyam.com');

        // Note: payroll ("Staff salaries") is intentionally NOT seeded here.
        // Salaries flow through the salaries/payslips tables to avoid
        // double-counting in Reports Net Profit.
        $rows = [
            ['category' => 'operations', 'description' => 'Office rent - July',           'amount' => 25000,  'date' => '2026-07-01', 'client_id' => null,                            'paid_to' => 'Landlord',      'payment_method' => 'bank', 'status' => 'paid', 'created_by' => $super],
            ['category' => 'equipment',  'description' => 'Camera lens rental',           'amount' => 8000,   'date' => '2026-07-05', 'client_id' => $c('Himalayan Coffee'),          'paid_to' => 'Rentals Nepal', 'payment_method' => 'cash', 'status' => 'paid', 'created_by' => $videographer],
            ['category' => 'travel',     'description' => 'Shoot travel - Pokhara',       'amount' => 12000,  'date' => '2026-07-10', 'client_id' => $c('Nepal Trek Adventures'),     'paid_to' => 'Staff',         'payment_method' => 'cash', 'status' => 'paid', 'created_by' => $manager],
            ['category' => 'software',   'description' => 'Adobe Creative Suite',         'amount' => 15000,  'date' => '2026-07-01', 'client_id' => null,                            'paid_to' => 'Adobe',         'payment_method' => 'card', 'status' => 'paid', 'created_by' => $super],
        ];

        foreach ($rows as $r) {
            DB::table('expenses')->insert(array_merge($r, [
                'staff_member_id' => null,
                'location' => null,
                'item_name' => null,
                'destination' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
