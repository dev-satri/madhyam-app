<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test invoices.
 *
 * 6 invoices covering every payment_status the app supports so both
 * the admin invoices UI and the client-portal billing UI have complete
 * coverage during manual testing:
 *
 *   1. paid / full        — historic (Himalayan Coffee, last month)
 *   2. pending / installment — client 1, partial payment recorded
 *   3. paid / full        — historic (Trek Nepal, last month)
 *   4. pending / half     — client 2, half paid
 *   5. overdue / pending  — client 1, due date in the past
 *   6. paid / discount    — client 2, discount applied
 */
class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $c2 = DB::table('clients')->where('name', 'Trek Nepal Adventures')->value('id');

        $rows = [
            [
                'client_id' => $c1, 'amount' => 45000, 'status' => 'paid', 'payment_status' => 'full',
                'due_date' => now()->subMonth()->startOfMonth()->addDays(4)->toDateString(),
                'description' => 'Last-month retainer — Himalayan Coffee Co.',
                'paid_date' => now()->subMonth()->startOfMonth()->addDays(2)->toDateString(),
                'discount' => 0, 'discount_amount' => 0, 'installment_plan' => null,
            ],
            [
                'client_id' => $c1, 'amount' => 45000, 'status' => 'pending', 'payment_status' => 'installment',
                'due_date' => now()->addDays(10)->toDateString(),
                'description' => 'Current-month retainer — Himalayan Coffee Co. (installment)',
                'paid_date' => null,
                'discount' => 0, 'discount_amount' => 0,
                'installment_plan' => json_encode(['totalInstallments' => 3, 'paidInstallments' => 1, 'amountPerInstallment' => 15000]),
            ],
            [
                'client_id' => $c2, 'amount' => 30000, 'status' => 'paid', 'payment_status' => 'full',
                'due_date' => now()->subMonth()->startOfMonth()->addDays(4)->toDateString(),
                'description' => 'Last-month retainer — Trek Nepal Adventures',
                'paid_date' => now()->subMonth()->startOfMonth()->addDays(6)->toDateString(),
                'discount' => 0, 'discount_amount' => 0, 'installment_plan' => null,
            ],
            [
                'client_id' => $c2, 'amount' => 30000, 'status' => 'pending', 'payment_status' => 'half',
                'due_date' => now()->addDays(7)->toDateString(),
                'description' => 'Current-month retainer — Trek Nepal Adventures (half paid)',
                'paid_date' => null,
                'discount' => 0, 'discount_amount' => 0, 'installment_plan' => null,
            ],
            [
                'client_id' => $c1, 'amount' => 12000, 'status' => 'overdue', 'payment_status' => 'pending',
                'due_date' => now()->subDays(14)->toDateString(),
                'description' => 'Extra shoot day — Himalayan Coffee Co.',
                'paid_date' => null,
                'discount' => 0, 'discount_amount' => 0, 'installment_plan' => null,
            ],
            [
                'client_id' => $c2, 'amount' => 27000, 'status' => 'paid', 'payment_status' => 'discount',
                'due_date' => now()->subDays(5)->toDateString(),
                'description' => 'Bonus campaign — Trek Nepal Adventures (loyalty discount)',
                'paid_date' => now()->subDays(4)->toDateString(),
                'discount' => 3000, 'discount_amount' => 3000, 'installment_plan' => null,
            ],
        ];

        foreach ($rows as $r) {
            DB::table('invoices')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command?->info('  ✓ Invoices: 6 (paid, pending-installment, pending-half, overdue, discount)');
    }
}
