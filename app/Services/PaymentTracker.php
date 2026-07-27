<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Auto-recalculate the two-status model for an invoice per plan §24.
 *
 * Two orthogonal statuses:
 *   - `status`         invoice-level: paid / pending / overdue
 *   - `payment_status` payment-progress: pending / half / full / installment / discount
 *
 * Rules (spec §24.5):
 *   totalPaid  = sum(invoice_payments.amount)
 *   netAmount  = invoice.amount - invoice.discount_amount
 *   if totalPaid >= netAmount → status=paid, payment_status=full
 *   else if totalPaid >= netAmount * 0.5 → payment_status=half
 *   overdue when past due_date and not fully paid
 *
 * `installment` is a plan type (set when installment_plan JSON exists),
 * not derived from partial payment.
 * `discount` payment_status is a display label for an unpaid invoice that
 * has a discount applied; a fully-paid discounted invoice is `full`, not `discount`.
 */
class PaymentTracker
{
    public function recalc(Invoice $invoice): Invoice
    {
        $invoice->refresh();

        $net = (float) $invoice->net_amount;
        $totalPaid = (float) $invoice->total_paid;
        $discount = (float) $invoice->discount_amount;
        $hasPlan = ! empty($invoice->installment_plan);
        $overdueByDate = $invoice->due_date && Carbon::parse($invoice->due_date)->isPast();

        // Fully paid — always wins.
        if ($net > 0 && $totalPaid >= $net) {
            $invoice->status = 'paid';
            $invoice->payment_status = 'full';
            $invoice->paid_date = $invoice->paid_date ?? Carbon::today();
        }
        // Partially paid, ≥ 50%.
        elseif ($totalPaid > 0 && $totalPaid >= $net * 0.5) {
            $invoice->status = $overdueByDate ? 'overdue' : 'pending';
            $invoice->payment_status = 'half';
        }
        // Partial payment on an installment plan.
        elseif ($totalPaid > 0 && $hasPlan) {
            $invoice->status = $overdueByDate ? 'overdue' : 'pending';
            $invoice->payment_status = 'installment';
        }
        // Unpaid but with a discount.
        elseif ($totalPaid <= 0 && $discount > 0) {
            $invoice->status = $overdueByDate ? 'overdue' : 'pending';
            $invoice->payment_status = 'discount';
        }
        // Overdue and unpaid.
        elseif ($overdueByDate) {
            $invoice->status = 'overdue';
            $invoice->payment_status = 'pending';
        }
        // Default: not paid, not overdue.
        else {
            $invoice->status = 'pending';
            $invoice->payment_status = 'pending';
        }

        // Update installment plan progress before saving.
        if ($hasPlan) {
            $plan = is_array($invoice->installment_plan)
                ? $invoice->installment_plan
                : json_decode($invoice->installment_plan, true);

            if (is_array($plan)) {
                $perInstallment = (float) ($plan['amountPerInstallment'] ?? 0);
                if ($perInstallment > 0) {
                    $plan['paidInstallments'] = (int) floor($totalPaid / $perInstallment);
                }
                $invoice->installment_plan = $plan;
            }
        }

        $invoice->save();

        return $invoice;
    }
}
