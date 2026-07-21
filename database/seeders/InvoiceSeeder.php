<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $c = fn (string $name) => DB::table('clients')->where('name', $name)->value('id');
        $c1 = $c('Himalayan Coffee');
        $c2 = $c('Nepal Trek Adventures');
        $c3 = $c('Kathmandu Bites');
        $c4 = $c('GreenLeaf Organic');
        $c5 = $c('Mountain View Resort');

        $rows = [
            ['client_id' => $c1, 'amount' => 45000, 'status' => 'paid',    'payment_status' => 'full',        'due_date' => '2026-06-01', 'description' => 'June 2026 - Himalayan Coffee', 'paid_date' => '2026-05-28', 'discount' => 0,    'discount_amount' => 0, 'installment_plan' => null],
            ['client_id' => $c1, 'amount' => 45000, 'status' => 'pending', 'payment_status' => 'installment', 'due_date' => '2026-07-01', 'description' => 'July 2026 - Himalayan Coffee', 'paid_date' => null,         'discount' => 0,    'discount_amount' => 0, 'installment_plan' => json_encode(['totalInstallments' => 3, 'paidInstallments' => 1, 'amountPerInstallment' => 15000])],
            ['client_id' => $c2, 'amount' => 30000, 'status' => 'paid',    'payment_status' => 'full',        'due_date' => '2026-06-01', 'description' => 'June 2026 - Nepal Trek',       'paid_date' => '2026-06-02', 'discount' => 0,    'discount_amount' => 0, 'installment_plan' => null],
            ['client_id' => $c2, 'amount' => 30000, 'status' => 'pending', 'payment_status' => 'half',        'due_date' => '2026-07-01', 'description' => 'July 2026 - Nepal Trek',       'paid_date' => null,         'discount' => 0,    'discount_amount' => 0, 'installment_plan' => null],
            ['client_id' => $c3, 'amount' => 18000, 'status' => 'overdue', 'payment_status' => 'pending',     'due_date' => '2026-06-15', 'description' => 'June 2026 - KTM Bites',        'paid_date' => null,         'discount' => 2000, 'discount_amount' => 2000, 'installment_plan' => null],
            ['client_id' => $c4, 'amount' => 75000, 'status' => 'paid',    'payment_status' => 'full',        'due_date' => '2026-06-01', 'description' => 'June 2026 - GreenLeaf',        'paid_date' => '2026-05-25', 'discount' => 0,    'discount_amount' => 0, 'installment_plan' => null],
            ['client_id' => $c4, 'amount' => 75000, 'status' => 'paid',    'payment_status' => 'discount',    'due_date' => '2026-07-01', 'description' => 'July 2026 - GreenLeaf',        'paid_date' => '2026-06-30', 'discount' => 5000, 'discount_amount' => 5000, 'installment_plan' => null],
            ['client_id' => $c5, 'amount' => 35000, 'status' => 'pending', 'payment_status' => 'pending',     'due_date' => '2026-07-01', 'description' => 'July 2026 - Mountain View',    'paid_date' => null,         'discount' => 0,    'discount_amount' => 0, 'installment_plan' => null],
        ];

        foreach ($rows as $r) {
            DB::table('invoices')->insert(array_merge($r, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
