<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test invoice payments — matches the invoices seeded by InvoiceSeeder.
 *
 * Rules:
 *  - Every 'paid' invoice gets a single full payment row.
 *  - The installment invoice gets one partial payment (1 of 3).
 *  - The half-paid invoice gets one payment of half the amount.
 *  - The overdue invoice gets no payments (as expected).
 */
class InvoicePaymentSeeder extends Seeder
{
    public function run(): void
    {
        // Full payments
        $paid = DB::table('invoices')->where('status', 'paid')->get();
        foreach ($paid as $invoice) {
            DB::table('invoice_payments')->insert([
                'invoice_id' => $invoice->id,
                'amount' => $invoice->amount,
                'date' => $invoice->paid_date ?? now()->toDateString(),
                'method' => 'bank',
                'note' => 'Full payment received.',
                'verified' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Installment (single partial payment already recorded)
        $installment = DB::table('invoices')->where('payment_status', 'installment')->first();
        if ($installment) {
            DB::table('invoice_payments')->insert([
                'invoice_id' => $installment->id,
                'amount' => 15000,
                'date' => now()->subDays(12)->toDateString(),
                'method' => 'esewa',
                'note' => 'Installment 1 of 3.',
                'verified' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Half payment
        $half = DB::table('invoices')->where('payment_status', 'half')->first();
        if ($half) {
            DB::table('invoice_payments')->insert([
                'invoice_id' => $half->id,
                'amount' => $half->amount / 2,
                'date' => now()->subDays(3)->toDateString(),
                'method' => 'khalti',
                'note' => 'Half advance payment.',
                'verified' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info('  ✓ Invoice payments: paid + installment (1/3) + half seeded');
    }
}
