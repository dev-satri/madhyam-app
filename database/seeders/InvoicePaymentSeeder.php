<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoicePaymentSeeder extends Seeder
{
    public function run(): void
    {
        $paidInvoices = DB::table('invoices')
            ->where('status', 'paid')
            ->select('id', 'amount', 'paid_date')
            ->get();

        foreach ($paidInvoices as $invoice) {
            DB::table('invoice_payments')->insert([
                'invoice_id' => $invoice->id,
                'amount' => $invoice->amount,
                'date' => $invoice->paid_date ?? now()->toDateString(),
                'method' => ['cash', 'bank', 'esewa', 'khalti'][array_rand(['cash', 'bank', 'esewa', 'khalti'])],
                'note' => 'Full payment received.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $installmentInvoice = DB::table('invoices')
            ->where('payment_status', 'installment')
            ->first();

        if ($installmentInvoice) {
            $paidAmount = $installmentInvoice->amount / 3;
            DB::table('invoice_payments')->insert([
                'invoice_id' => $installmentInvoice->id,
                'amount' => $paidAmount,
                'date' => now()->subDays(15)->toDateString(),
                'method' => 'bank',
                'note' => 'First installment.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $halfInvoice = DB::table('invoices')
            ->where('payment_status', 'half')
            ->first();

        if ($halfInvoice) {
            DB::table('invoice_payments')->insert([
                'invoice_id' => $halfInvoice->id,
                'amount' => $halfInvoice->amount / 2,
                'date' => now()->subDays(5)->toDateString(),
                'method' => 'esewa',
                'note' => 'Half payment.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
