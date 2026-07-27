<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test expenses — 5 rows across every expense category the
 * Reports > Net Profit widget cares about.
 *
 * Payroll is intentionally NOT seeded here — salaries flow through
 * the salaries table and would be double-counted otherwise.
 */
class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = DB::table('users')->where('email', 'superadmin@madhyam.com')->value('id');
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');
        $videographer = DB::table('users')->where('email', 'staff.video@madhyam.com')->value('id');

        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $c2 = DB::table('clients')->where('name', 'Trek Nepal Adventures')->value('id');

        $rows = [
            ['category' => 'operations', 'description' => 'Office rent — July',                     'amount' => 25000, 'date' => now()->startOfMonth()->toDateString(),          'client_id' => null, 'paid_to' => 'Landlord',      'payment_method' => 'bank',   'status' => 'paid', 'created_by' => $superAdmin],
            ['category' => 'equipment',  'description' => 'Camera lens rental (85mm)',              'amount' => 8000,  'date' => now()->subDays(6)->toDateString(),              'client_id' => $c1,  'paid_to' => 'Rentals Nepal', 'payment_method' => 'cash',   'status' => 'paid', 'created_by' => $videographer],
            ['category' => 'travel',     'description' => 'Pokhara shoot — flights & lodging',     'amount' => 12000, 'date' => now()->subDays(10)->toDateString(),             'client_id' => $c2,  'paid_to' => 'Yeti Airlines', 'payment_method' => 'card',   'status' => 'paid', 'created_by' => $admin],
            ['category' => 'software',   'description' => 'Adobe Creative Suite — July',            'amount' => 15000, 'date' => now()->startOfMonth()->toDateString(),          'client_id' => null, 'paid_to' => 'Adobe Systems', 'payment_method' => 'card',   'status' => 'paid', 'created_by' => $superAdmin],
            ['category' => 'marketing',  'description' => 'Meta Ads boost — Himalayan Coffee',      'amount' => 6000,  'date' => now()->subDays(3)->toDateString(),              'client_id' => $c1,  'paid_to' => 'Meta',          'payment_method' => 'card',   'status' => 'paid', 'created_by' => $admin],
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

        $this->command?->info('  ✓ Expenses: 5 across operations, equipment, travel, software, marketing');
    }
}
