<?php

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Notifications\InvoiceCreatedNotification;
use App\Notifications\InvoicePaymentReceivedNotification;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\PaymentTracker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $activeTab = 'invoices';

    public string $search = '';

    public string $statusFilter = '';

    public string $clientFilter = '';

    public bool $showInvoiceForm = false;

    public bool $showRecordPayment = false;

    public bool $showDetail = false;

    public int $editingId = 0;

    public int $detailId = 0;

    public int $paymentInvoiceId = 0;

    // Invoice form fields
    public int $formClientId = 0;

    public string $formAmount = '';

    public string $formStatus = 'pending';

    public string $formDueDate = '';

    public string $formDescription = '';

    public string $formPaymentStatus = 'pending';

    public string $formDiscountAmount = '';

    public int $formTotalInstallments = 0;

    public int $formPaidInstallments = 0;

    public string $formAmountPerInstallment = '';

    // Record payment fields
    public string $payAmount = '';

    public string $payDate = '';

    public string $payMethod = 'cash';

    public string $payNote = '';

    public $payProof = null;

    public function mount(): void
    {
        $this->payDate = now()->format('Y-m-d');
    }

    public function isClientPortal(): bool
    {
        return Auth::guard('client')->check();
    }

    /**
     * Scope a query builder to the current client's rows when the client
     * guard is active. No-op for staff (web guard) so admin totals stay global.
     * Applied to every raw invoices/payments read to prevent cross-tenant leaks.
     */
    private function scopeToClient($query, string $column = 'invoices.client_id')
    {
        $account = Auth::guard('client')->user();
        if ($account) {
            $query->where($column, $account->client_id);
        }

        return $query;
    }

    public function getStats(): array
    {
        $invoices = $this->scopeToClient(DB::table('invoices'));
        $totalRevenue = (clone $invoices)->sum('amount');
        $paid = (clone $invoices)->where('status', 'paid')->sum('amount');
        $pending = (clone $invoices)->where('status', 'pending')->sum('amount');
        $overdue = (clone $invoices)->where('status', 'overdue')->sum('amount');
        // Non-payroll expenses only. Payroll is summed separately from
        // `salaries` (or future `payslips`) to avoid double counting Net Profit.
        // Agency-level metric — never exposed to clients.
        $expenses = $this->isClientPortal()
            ? 0
            : DB::table('expenses')->where('category', '!=', 'salary')->sum('amount');
        $discounts = (clone $invoices)->sum('discount_amount');
        $installmentDue = (clone $invoices)->where('payment_status', 'installment')->sum('amount');

        return [
            'revenue' => $totalRevenue,
            'paid' => $paid,
            'pending' => $pending,
            'overdue' => $overdue,
            'expenses' => $expenses,
            'net_profit' => $this->isClientPortal() ? 0 : ($totalRevenue - $expenses),
            'discount' => $discounts,
            'installment_due' => $installmentDue,
        ];
    }

    public function getInvoices()
    {
        $q = DB::table('invoices')->leftJoin('clients', 'invoices.client_id', '=', 'clients.id');
        $this->scopeToClient($q);

        if ($this->search) {
            $q->where(function ($q) {
                $q->where('clients.name', 'like', "%{$this->search}%")
                    ->orWhere('invoices.description', 'like', "%{$this->search}%");
            });
        }
        if ($this->statusFilter) {
            $q->where('invoices.status', $this->statusFilter);
        }
        if ($this->clientFilter) {
            $q->where('invoices.client_id', $this->clientFilter);
        }

        $invoices = $q->select('invoices.*', 'clients.name as client_name')
            ->orderBy('invoices.due_date', 'desc')
            ->get();

        $invoiceIds = $invoices->pluck('id')->toArray();
        $payments = ! empty($invoiceIds)
            ? DB::table('invoice_payments')
                ->whereIn('invoice_id', $invoiceIds)
                ->selectRaw('invoice_id, SUM(amount) as total_paid')
                ->groupBy('invoice_id')
                ->get()
                ->keyBy('invoice_id')
            : collect();

        return $invoices->map(function ($inv) use ($payments) {
            $inv->total_paid = $payments[$inv->id]->total_paid ?? 0;
            $inv->remaining = $inv->amount - $inv->discount_amount - $inv->total_paid;
            if ($inv->payment_status === 'installment' && $inv->installment_plan) {
                $plan = is_string($inv->installment_plan) ? json_decode($inv->installment_plan, true) : $inv->installment_plan;
                $inv->installment_progress = ($plan['paidInstallments'] ?? 0) . '/' . ($plan['totalInstallments'] ?? 0);
            }

            return $inv;
        });
    }

    public function getPayments(int $invoiceId)
    {
        // Ownership gate: a client may only see payments for their own invoices.
        // invoice_payments has no client_id, so we scope transitively via invoices.
        $owned = $this->scopeToClient(DB::table('invoices'))
            ->where('id', $invoiceId)
            ->exists();
        if (! $owned) {
            return collect();
        }

        return DB::table('invoice_payments')
            ->where('invoice_id', $invoiceId)
            ->orderBy('date', 'desc')
            ->get();
    }

    public function openInvoiceForm(?int $id = null): void
    {
        abort_if($this->isClientPortal(), 403);
        if ($id) {
            $inv = DB::table('invoices')->where('id', $id)->first();
            if ($inv) {
                $this->editingId = $id;
                $this->formClientId = $inv->client_id;
                $this->formAmount = $inv->amount;
                $this->formStatus = $inv->status;
                $this->formDueDate = $inv->due_date;
                $this->formDescription = $inv->description ?? '';
                $this->formPaymentStatus = $inv->payment_status;
                $this->formDiscountAmount = $inv->discount_amount;
                if ($inv->installment_plan) {
                    $plan = is_string($inv->installment_plan) ? json_decode($inv->installment_plan, true) : $inv->installment_plan;
                    $this->formTotalInstallments = $plan['totalInstallments'] ?? 0;
                    $this->formPaidInstallments = $plan['paidInstallments'] ?? 0;
                    $this->formAmountPerInstallment = $plan['amountPerInstallment'] ?? '';
                }
            }
        } else {
            $this->editingId = 0;
            $this->formClientId = 0;
            $this->formAmount = '';
            $this->formStatus = 'pending';
            $this->formDueDate = now()->addDays(30)->format('Y-m-d');
            $this->formDescription = '';
            $this->formPaymentStatus = 'pending';
            $this->formDiscountAmount = '';
            $this->formTotalInstallments = 0;
            $this->formPaidInstallments = 0;
            $this->formAmountPerInstallment = '';
        }
        $this->showInvoiceForm = true;
    }

    public function saveInvoice(): void
    {
        abort_if($this->isClientPortal(), 403);
        $this->validate([
            'formClientId' => 'required|integer',
            'formAmount' => 'required|numeric|min:0',
            'formDueDate' => 'required|date',
        ]);

        $installmentPlan = null;
        if ($this->formPaymentStatus === 'installment' && $this->formTotalInstallments > 0) {
            $installmentPlan = json_encode([
                'totalInstallments' => $this->formTotalInstallments,
                'paidInstallments' => $this->formPaidInstallments,
                'amountPerInstallment' => $this->formAmountPerInstallment,
            ]);
        }

        $data = [
            'client_id' => $this->formClientId,
            'amount' => $this->formAmount,
            'status' => $this->formStatus,
            'due_date' => $this->formDueDate,
            'description' => $this->formDescription ?: null,
            'payment_status' => $this->formPaymentStatus,
            'discount_amount' => $this->formDiscountAmount ?: 0,
            'installment_plan' => $installmentPlan,
        ];

        if ($this->editingId) {
            DB::table('invoices')->where('id', $this->editingId)->update($data);
            $invoiceId = $this->editingId;
            $verb = 'updated';
        } else {
            $data['created_at'] = now();
            $data['updated_at'] = now();
            $invoiceId = DB::table('invoices')->insertGetId($data);
            $verb = 'created';
        }

        // Normalize status via PaymentTracker (handles overdue/discount/installment edge cases).
        if ($invoice = Invoice::find($invoiceId)) {
            app(PaymentTracker::class)->recalc($invoice);

            // Send invoice created notification to client (only for new invoices).
            // Sends to the primary client email AND any portal accounts (deduped),
            // so clients without a portal login still receive the invoice.
            if ($verb === 'created' && $invoice->client && $invoice->client->email) {
                $sent = [];
                Notification::route('mail', $invoice->client->email)
                    ->notify(new InvoiceCreatedNotification($invoice));
                $sent[] = strtolower($invoice->client->email);
                $invoice->client->accounts->each(function ($account) use ($invoice, &$sent) {
                    if ($account->email && ! in_array(strtolower($account->email), $sent, true)) {
                        $account->notify(new InvoiceCreatedNotification($invoice));
                        $sent[] = strtolower($account->email);
                    }
                });
            }
        }

        // In-app notification for admin/manager
        $clientName = $invoice->client->name ?? 'Unknown';
        $amount = number_format($this->formAmount, 2);
        app(NotificationService::class)->sendNotification(
            text: "Invoice #{$invoiceId} {$verb} for {$clientName} — NPR {$amount}",
            type: 'info',
            link: route('reports', absolute: false),
            forRole: 'admin',
        );
        app(NotificationService::class)->sendNotification(
            text: "Invoice #{$invoiceId} {$verb} for {$clientName} — NPR {$amount}",
            type: 'info',
            link: route('reports', absolute: false),
            forRole: 'manager',
        );

        app(ActivityLogger::class)->record(Auth::user(), "Invoice #{$invoiceId} {$verb}");

        $this->showInvoiceForm = false;
        $this->dispatch('toast', message: 'Invoice saved', type: 'success');
    }

    public function openRecordPayment(int $invoiceId): void
    {
        abort_if($this->isClientPortal(), 403);
        $this->paymentInvoiceId = $invoiceId;
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if ($inv) {
            $this->payAmount = '';
            $this->payDate = now()->format('Y-m-d');
            $this->payMethod = 'cash';
            $this->payNote = '';
            $this->showRecordPayment = true;
        }
    }

    public function getPaymentInvoice()
    {
        return DB::table('invoices')->where('id', $this->paymentInvoiceId)->first();
    }

    public function getPaymentContext(): array
    {
        $inv = DB::table('invoices')->where('id', $this->paymentInvoiceId)->first();
        if (! $inv) {
            return ['remaining' => 0, 'is_installment' => false];
        }

        $totalPaid = (float) DB::table('invoice_payments')->where('invoice_id', $inv->id)->sum('amount');
        $netAmount = (float) $inv->amount - (float) $inv->discount_amount;
        $remaining = max(0, $netAmount - $totalPaid);
        $isInstallment = $inv->payment_status === 'installment' && ! empty($inv->installment_plan);

        $context = [
            'remaining' => $remaining,
            'net_amount' => $netAmount,
            'total_paid' => $totalPaid,
            'is_installment' => $isInstallment,
            'invoice_id' => $inv->id,
            'status' => $inv->status,
        ];

        if ($isInstallment) {
            $plan = is_string($inv->installment_plan) ? json_decode($inv->installment_plan, true) : $inv->installment_plan;
            $amountPerInstallment = (float) ($plan['amountPerInstallment'] ?? 0);
            $totalInstallments = (int) ($plan['totalInstallments'] ?? 0);
            $paidInstallments = $amountPerInstallment > 0 ? (int) floor($totalPaid / $amountPerInstallment) : 0;
            $currentInstallmentPaid = $totalPaid - ($paidInstallments * $amountPerInstallment);
            $currentInstallmentRemaining = max(0, $amountPerInstallment - $currentInstallmentPaid);

            $context['amount_per_installment'] = $amountPerInstallment;
            $context['total_installments'] = $totalInstallments;
            $context['paid_installments'] = min($paidInstallments, $totalInstallments);
            $context['current_installment_number'] = min($paidInstallments + 1, $totalInstallments);
            $context['current_installment_paid'] = $currentInstallmentPaid;
            $context['current_installment_remaining'] = $currentInstallmentRemaining;
            $context['expected_amount'] = $currentInstallmentRemaining;
        } else {
            $context['expected_amount'] = $remaining;
        }

        return $context;
    }

    public function savePayment(): void
    {
        abort_if($this->isClientPortal(), 403);
        $this->validate([
            'payAmount' => 'required|numeric|min:0.01',
            'payDate' => 'required|date',
            'payMethod' => 'required',
        ]);

        // Validate payment amount against remaining balance
        $context = $this->getPaymentContext();
        $payAmount = (float) $this->payAmount;

        if ($payAmount > $context['remaining'] + 0.01) {
            $this->dispatch('toast', message: 'Payment amount (NPR ' . number_format($payAmount, 2) . ') exceeds remaining balance (NPR ' . number_format($context['remaining'], 2) . ')', type: 'error');

            return;
        }

        if ($context['is_installment']) {
            $expected = $context['expected_amount'];
            if ($payAmount < $expected - 0.01 && $payAmount < $context['remaining'] - 0.01) {
                $this->dispatch('toast', message: 'Installment expected: NPR ' . number_format($expected, 2) . '. Partial payment of NPR ' . number_format($payAmount, 2) . ' recorded — remaining NPR ' . number_format($expected - $payAmount, 2) . ' carried to next installment.', type: 'warning');
            }
        }

        $proofPath = null;
        if ($this->payProof) {
            $proofPath = $this->payProof->store('payment-proofs', 'public');
        }

        $paymentId = DB::table('invoice_payments')->insertGetId([
            'invoice_id' => $this->paymentInvoiceId,
            'amount' => $this->payAmount,
            'date' => $this->payDate,
            'method' => $this->payMethod,
            'note' => $this->payNote ?: null,
            'proof_path' => $proofPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Auto-update installment paidInstallments counter
        if ($invoice = Invoice::find($this->paymentInvoiceId)) {
            if ($invoice->installment_plan) {
                $plan = is_array($invoice->installment_plan) ? $invoice->installment_plan : json_decode($invoice->installment_plan, true);
                if ($plan && isset($plan['totalInstallments'])) {
                    $totalPaid = (float) $invoice->payments->sum('amount');
                    $amountPerInstallment = (float) ($plan['amountPerInstallment'] ?? 0);
                    if ($amountPerInstallment > 0) {
                        $plan['paidInstallments'] = min(
                            (int) $plan['totalInstallments'],
                            (int) floor($totalPaid / $amountPerInstallment)
                        );
                    }
                    $invoice->installment_plan = $plan;
                    $invoice->save();
                }
            }

            // Recalculate invoice status via the canonical service (plan §24.5).
            app(PaymentTracker::class)->recalc($invoice);

            // Refresh to get updated status after recalc
            $invoice->refresh();

            // Send payment received notification to client via email.
            // Sends to the primary client email AND any portal accounts (deduped),
            // so clients without a portal login still receive the payment receipt.
            $payment = InvoicePayment::find($paymentId);
            if ($payment && $invoice->client && $invoice->client->email) {
                $sent = [];
                Notification::route('mail', $invoice->client->email)
                    ->notify(new InvoicePaymentReceivedNotification($invoice, $payment));
                $sent[] = strtolower($invoice->client->email);
                $invoice->client->accounts->each(function ($account) use ($invoice, $payment, &$sent) {
                    if ($account->email && ! in_array(strtolower($account->email), $sent, true)) {
                        $account->notify(new InvoicePaymentReceivedNotification($invoice, $payment));
                        $sent[] = strtolower($account->email);
                    }
                });
            }

            // In-app notification text
            $clientName = $invoice->client->name ?? 'Unknown';
            $paymentAmount = number_format($this->payAmount, 2);
            $statusText = $invoice->status === 'paid' ? ' (FULLY PAID)' : '';

            // Notify admin
            app(NotificationService::class)->sendNotification(
                text: "Payment of NPR {$paymentAmount} received from {$clientName} for invoice #{$this->paymentInvoiceId}{$statusText}",
                type: 'success',
                link: route('reports', absolute: false),
                forRole: 'admin',
            );

            // Notify manager
            app(NotificationService::class)->sendNotification(
                text: "Payment of NPR {$paymentAmount} received from {$clientName} for invoice #{$this->paymentInvoiceId}{$statusText}",
                type: 'success',
                link: route('reports', absolute: false),
                forRole: 'manager',
            );

            // Also send email notification to admin/manager
            $managerEmails = User::whereIn('role', ['admin', 'manager'])->pluck('email')->filter()->toArray();
            foreach ($managerEmails as $email) {
                app(NotificationService::class)->emailNotify(
                    to: $email,
                    subject: "Payment Received — NPR {$paymentAmount} from {$clientName}",
                    body: "A payment of NPR {$paymentAmount} has been received from {$clientName} for Invoice #{$this->paymentInvoiceId}.\n\nMethod: " . ucfirst($this->payMethod) . "\nDate: {$this->payDate}\n\n" . ($invoice->status === 'paid' ? "This invoice is now FULLY PAID.\n\n" : 'Remaining balance: NPR ' . number_format($invoice->net_amount - $invoice->total_paid, 2) . "\n\n") . 'View invoice: ' . route('reports'),
                );
            }
        }

        app(ActivityLogger::class)->record(
            Auth::user(),
            "Payment of {$this->payAmount} recorded for invoice #{$this->paymentInvoiceId}"
        );

        $this->showRecordPayment = false;
        $this->dispatch('toast', message: 'Payment recorded' . ($invoice->status === 'paid' ? ' — Invoice fully paid!' : ''), type: 'success');
    }

    public function markAsPaid(int $invoiceId): void
    {
        abort_if($this->isClientPortal(), 403);

        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            return;
        }

        $remaining = max(0, $invoice->net_amount - $invoice->total_paid);

        // Record a payment for the remaining balance if there's anything left
        if ($remaining > 0) {
            DB::table('invoice_payments')->insert([
                'invoice_id' => $invoiceId,
                'amount' => $remaining,
                'date' => now()->toDateString(),
                'method' => 'cash',
                'note' => 'Marked as paid by ' . Auth::user()->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Recalculate status
        $invoice->refresh();
        app(PaymentTracker::class)->recalc($invoice);
        $invoice->refresh();

        // Send notifications
        $clientName = $invoice->client->name ?? 'Unknown';
        $paymentAmount = number_format($remaining, 2);

        // Email to client — primary email + portal accounts, deduped.
        if ($invoice->client && $invoice->client->email) {
            $payment = InvoicePayment::where('invoice_id', $invoiceId)->latest()->first();
            if ($payment) {
                $sent = [];
                Notification::route('mail', $invoice->client->email)
                    ->notify(new InvoicePaymentReceivedNotification($invoice, $payment));
                $sent[] = strtolower($invoice->client->email);
                $invoice->client->accounts->each(function ($account) use ($invoice, $payment, &$sent) {
                    if ($account->email && ! in_array(strtolower($account->email), $sent, true)) {
                        $account->notify(new InvoicePaymentReceivedNotification($invoice, $payment));
                        $sent[] = strtolower($account->email);
                    }
                });
            }
        }

        // In-app notifications
        app(NotificationService::class)->sendNotification(
            text: "Invoice #{$invoiceId} ({$clientName}) marked as paid" . ($remaining > 0 ? " — NPR {$paymentAmount} balance settled" : ''),
            type: 'success',
            link: route('reports', absolute: false),
            forRole: 'admin',
        );
        app(NotificationService::class)->sendNotification(
            text: "Invoice #{$invoiceId} ({$clientName}) marked as paid" . ($remaining > 0 ? " — NPR {$paymentAmount} balance settled" : ''),
            type: 'success',
            link: route('reports', absolute: false),
            forRole: 'manager',
        );

        // Email to admin/manager
        $managerEmails = User::whereIn('role', ['admin', 'manager'])->pluck('email')->filter()->toArray();
        foreach ($managerEmails as $email) {
            app(NotificationService::class)->emailNotify(
                to: $email,
                subject: "Invoice #{$invoiceId} Marked as Paid — {$clientName}",
                body: "Invoice #{$invoiceId} for {$clientName} has been marked as paid.\n\n" . ($remaining > 0 ? "A final payment of NPR {$paymentAmount} was recorded to settle the remaining balance.\n\n" : '') . 'View invoice: ' . route('reports'),
            );
        }

        app(ActivityLogger::class)->record(Auth::user(), "Invoice #{$invoiceId} marked as paid");
        $this->dispatch('toast', message: 'Invoice marked as paid', type: 'success');
    }

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $this->showDetail = true;
    }

    public function getDetailInvoice()
    {
        // Client-scoped: a client requesting another tenant's invoice id returns null.
        $q = DB::table('invoices')
            ->leftJoin('clients', 'invoices.client_id', '=', 'clients.id');
        $this->scopeToClient($q);

        return $q->where('invoices.id', $this->detailId)
            ->select('invoices.*', 'clients.name as client_name')
            ->first();
    }

    public function deleteInvoice(int $id): void
    {
        abort_if($this->isClientPortal(), 403);
        DB::table('invoice_payments')->where('invoice_id', $id)->delete();
        Invoice::findOrFail($id)->delete();
        app(ActivityLogger::class)->record(Auth::user(), "Deleted invoice #{$id}");
        $this->dispatch('toast', message: 'Invoice deleted', type: 'success');
    }

    public function exportCsv(): StreamedResponse
    {
        $invoices = $this->getInvoices();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="financial_report.csv"',
        ];

        return response()->stream(function () use ($invoices) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Client', 'Description', 'Amount', 'Status', 'Payment Status', 'Discount', 'Due Date', 'Total Paid', 'Remaining']);

            foreach ($invoices as $inv) {
                fputcsv($handle, [
                    $inv->client_name,
                    $inv->description,
                    $inv->amount,
                    $inv->status,
                    $inv->payment_status,
                    $inv->discount_amount,
                    $inv->due_date,
                    $inv->total_paid,
                    $inv->remaining,
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    public function getSalaryStats(): array
    {
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');
        $salaries = DB::table('salaries')->where('month', $month)->where('year', $year);

        $totalPayroll = (clone $salaries)->sum('net_salary');
        $paid = (clone $salaries)->where('status', 'paid')->sum('net_salary');
        $pending = (clone $salaries)->where('status', 'pending')->sum('net_salary');
        $approved = (clone $salaries)->where('status', 'approved')->sum('net_salary');
        $totalOT = (clone $salaries)->sum('overtime_pay');
        $totalBonus = (clone $salaries)->sum('bonus');
        $totalDeductions = (clone $salaries)->sum('leave_deduction');
        $memberCount = (clone $salaries)->count();

        return [
            'total_payroll' => $totalPayroll,
            'paid' => $paid,
            'pending' => $pending,
            'approved' => $approved,
            'total_ot' => $totalOT,
            'total_bonus' => $totalBonus,
            'total_deductions' => $totalDeductions,
            'member_count' => $memberCount,
        ];
    }

    public function getWorkerOverview()
    {
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');

        $salaries = DB::table('salaries')
            ->join('users', 'salaries.member_id', '=', 'users.id')
            ->leftJoin('departments', 'users.department_id', '=', 'departments.id')
            ->where('salaries.month', $month)
            ->where('salaries.year', $year)
            ->select(
                'salaries.*',
                'users.name as member_name',
                'users.role as member_role',
                'departments.name as dept_name'
            )
            ->orderBy('users.name')
            ->get();

        return $salaries->map(function ($s) {
            $otCount = DB::table('overtime_logs')
                ->where('member_id', $s->member_id)
                ->whereMonth('date', $s->month)
                ->whereYear('date', $s->year)
                ->where('approved', true)
                ->count();

            $leaveCount = DB::table('leaves')
                ->where('member_id', $s->member_id)
                ->where('status', 'approved')
                ->whereMonth('start_date', $s->month)
                ->whereYear('start_date', $s->year)
                ->count();

            $s->ot_days = $otCount;
            $s->leave_days = $leaveCount;

            return $s;
        });
    }

    public function getMonthlyExpenseSummary()
    {
        return DB::table('expenses')
            ->select('category', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->where('category', '!=', 'salary')
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();
    }

    public function getOvertimeSummary(): array
    {
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');

        $logs = DB::table('overtime_logs')
            ->join('users', 'overtime_logs.member_id', '=', 'users.id')
            ->whereMonth('overtime_logs.date', $month)
            ->whereYear('overtime_logs.date', $year);

        $totalHours = (clone $logs)->sum('overtime_logs.hours');
        $approvedHours = (clone $logs)->where('overtime_logs.approved', true)->sum('overtime_logs.hours');
        $pendingCount = (clone $logs)->where('overtime_logs.approved', false)->count();
        $totalCost = (clone $logs)->where('overtime_logs.approved', true)
            ->selectRaw('SUM(overtime_logs.hours * overtime_logs.rate) as total')
            ->value('total') ?? 0;

        return [
            'total_hours' => $totalHours,
            'approved_hours' => $approvedHours,
            'pending_count' => $pendingCount,
            'total_cost' => $totalCost,
        ];
    }

    public function verifyPayment(int $paymentId): void
    {
        abort_if($this->isClientPortal(), 403);
        DB::table('invoice_payments')->where('id', $paymentId)->update([
            'verified' => true,
            'verified_by' => Auth::user()->name,
            'verified_at' => now(),
        ]);
        app(ActivityLogger::class)->record(Auth::user(), "Payment #{$paymentId} verified");
        $this->dispatch('toast', message: 'Payment verified', type: 'success');
    }

    public function getProofUrl(int $paymentId): ?string
    {
        // Verify the payment's parent invoice is visible to the current viewer.
        $payment = DB::table('invoice_payments')->where('id', $paymentId)->first();
        if (! $payment) {
            return null;
        }

        $owned = $this->scopeToClient(DB::table('invoices'))
            ->where('id', $payment->invoice_id)
            ->exists();
        if (! $owned) {
            return null;
        }

        return $payment->proof_path ? Storage::url($payment->proof_path) : null;
    }

    #[Computed]
    public function stats(): array
    {
        return $this->getStats();
    }

    #[Computed]
    public function invoices()
    {
        return $this->getInvoices();
    }

    #[Computed]
    public function clients()
    {
        return DB::table('clients')->where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function isClient(): bool
    {
        return $this->isClientPortal();
    }

    #[Computed]
    public function salaryStats(): array
    {
        return $this->getSalaryStats();
    }

    #[Computed]
    public function workerOverview()
    {
        return $this->getWorkerOverview();
    }

    #[Computed]
    public function expenseSummary()
    {
        return $this->getMonthlyExpenseSummary();
    }

    #[Computed]
    public function overtimeSummary(): array
    {
        return $this->getOvertimeSummary();
    }

    public function getOutstandingSummary(): array
    {
        $invoices = DB::table('invoices')->where('status', '!=', 'paid');
        $this->scopeToClient($invoices);

        $all = $invoices->get();
        $total = (clone $invoices)->sum('amount') - (clone $invoices)->sum('discount_amount');

        // Calculate actual remaining by subtracting payments
        $invoiceIds = $all->pluck('id')->toArray();
        $payments = ! empty($invoiceIds)
            ? DB::table('invoice_payments')
                ->whereIn('invoice_id', $invoiceIds)
                ->selectRaw('invoice_id, SUM(amount) as total_paid')
                ->groupBy('invoice_id')
                ->get()
                ->keyBy('invoice_id')
            : collect();

        $totalRemaining = 0;
        $overdueAmount = 0;
        $overdueCount = 0;
        $pendingAmount = 0;
        $pendingCount = 0;
        $installmentAmount = 0;
        $installmentCount = 0;
        $oldestDue = null;

        foreach ($all as $inv) {
            $paid = $payments[$inv->id]->total_paid ?? 0;
            $remaining = max(0, $inv->amount - $inv->discount_amount - $paid);
            $totalRemaining += $remaining;

            if ($inv->status === 'overdue') {
                $overdueAmount += $remaining;
                $overdueCount++;
            } elseif ($inv->payment_status === 'installment') {
                $installmentAmount += $remaining;
                $installmentCount++;
            } else {
                $pendingAmount += $remaining;
                $pendingCount++;
            }

            if (! $oldestDue || $inv->due_date < $oldestDue) {
                $oldestDue = $inv->due_date;
            }
        }

        return [
            'count' => $all->count(),
            'total' => $totalRemaining,
            'overdue' => $overdueAmount,
            'overdue_count' => $overdueCount,
            'pending' => $pendingAmount,
            'pending_count' => $pendingCount,
            'installment' => $installmentAmount,
            'installment_count' => $installmentCount,
            'oldest_due' => $oldestDue,
        ];
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div><h1 class="text-2xl font-extrabold text-gray-900">{{ $this->isClient ? 'My Billing' : 'Reports & Finance' }}</h1><p class="text-sm text-gray-500">{{ $this->isClient ? 'View your invoices and payments' : 'Financial overview, invoices, salary & expenses' }}</p></div>
                @unless($this->isClient)
                    <div class="flex gap-2 no-print">
                        <button wire:click="exportCsv" class="btn btn-secondary"><i class="fas fa-download text-sm"></i> Export CSV</button>
                        <button wire:click="openInvoiceForm" class="btn btn-primary"><i class="fas fa-plus text-sm"></i> New Invoice</button>
                    </div>
                @endunless
            </div>

            {{-- Global Stats --}}
            <div wire:loading.class="opacity-60" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-dollar-sign"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['revenue']) }}</div><div class="stat-label">{{ $this->isClient ? 'Total Billed' : 'Total Revenue' }}</div></div>
                <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['paid']) }}</div><div class="stat-label">Paid</div></div>
                <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['pending']) }}</div><div class="stat-label">Pending</div></div>
                <div class="stat-card"><div class="stat-icon bg-red-100 text-red-600"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['overdue']) }}</div><div class="stat-label">Overdue</div></div>
            </div>

            @unless($this->isClient)
                <div wire:loading.class="opacity-60" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-orange-100 text-orange-600"><i class="fas fa-receipt"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['expenses']) }}</div><div class="stat-label">Total Expenses</div></div>
                    <div class="stat-card"><div class="stat-icon {{ $this->stats['net_profit'] >= 0 ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' }}"><i class="fas fa-chart-line"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['net_profit']) }}</div><div class="stat-label">Net Profit</div></div>
                    <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-percentage"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['discount']) }}</div><div class="stat-label">Discount Given</div></div>
                    <div class="stat-card"><div class="stat-icon bg-cyan-100 text-cyan-600"><i class="fas fa-calendar-alt"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['installment_due']) }}</div><div class="stat-label">Installment Due</div></div>
                </div>
            @endunless

            {{-- Tabs --}}
            @unless($this->isClient)
                <div class="border-b border-gray-200 overflow-x-auto">
                    <nav class="flex gap-0 -mb-px min-w-max">
                        <button wire:click="$set('activeTab', 'invoices')" class="px-4 py-2.5 text-sm font-semibold border-b-2 whitespace-nowrap transition-colors {{ $activeTab === 'invoices' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"><i class="fas fa-file-invoice mr-1.5"></i>Invoices</button>
                        <button wire:click="$set('activeTab', 'salary')" class="px-4 py-2.5 text-sm font-semibold border-b-2 whitespace-nowrap transition-colors {{ $activeTab === 'salary' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"><i class="fas fa-money-bill-wave mr-1.5"></i>Salary & Workers</button>
                        <button wire:click="$set('activeTab', 'expenses')" class="px-4 py-2.5 text-sm font-semibold border-b-2 whitespace-nowrap transition-colors {{ $activeTab === 'expenses' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"><i class="fas fa-receipt mr-1.5"></i>Expenses</button>
                        <button wire:click="$set('activeTab', 'overtime')" class="px-4 py-2.5 text-sm font-semibold border-b-2 whitespace-nowrap transition-colors {{ $activeTab === 'overtime' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"><i class="fas fa-clock mr-1.5"></i>Overtime</button>
                    </nav>
                </div>
            @endunless

            {{-- INVOICES TAB --}}
            @if($activeTab === 'invoices' || $this->isClient)
                {{-- Filters --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-start no-print">
                    <div><label class="form-label">Search</label><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="text" wire:model.live.debounce.250ms="search" placeholder="Search invoices..." class="form-input pl-10 focus:ring-0"></div></div>
                    <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="overdue">Overdue</option>
                    </select></div>
                    <div><label class="form-label">Client</label><select wire:model.live="clientFilter" class="form-select">
                        <option value="">All Clients</option>
                        @foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select></div>
                </div>

                <div class="overflow-x-auto">
                    <table class="data-table w-full">
                        <thead><tr>
                            <th>Client</th><th>Description</th><th>Amount</th><th>Status</th><th>Due Date</th><th class="text-right">Actions</th>
                        </tr></thead>
                        <tbody>
                            <!-- Skeleton Rows -->
                            @for($i = 0; $i < 5; $i++)
                                <tr wire:loading wire:target="search,statusFilter,clientFilter,activeTab">
                                    <td><div class="skeleton h-4 w-28"></div></td>
                                    <td><div class="skeleton h-4 w-40"></div></td>
                                    <td class="text-right"><div class="skeleton h-4 w-20 ml-auto"></div></td>
                                    <td>
                                        <div class="flex gap-1.5">
                                            <div class="skeleton h-5 w-16 rounded-full"></div>
                                            <div class="skeleton h-5 w-12 rounded-full"></div>
                                        </div>
                                    </td>
                                    <td><div class="skeleton h-4 w-24"></div></td>
                                    <td><div class="skeleton h-8 w-8 rounded-lg ml-auto"></div></td>
                                </tr>
                            @endfor

                            @forelse($this->invoices as $inv)
                                <tr wire:loading.remove wire:target="search,statusFilter,clientFilter,activeTab" class="{{ $inv->status === 'overdue' ? 'bg-red-50' : '' }}">
                                @php
                                    $sColor = match($inv->status) {
                                        'paid' => 'green',
                                        'overdue' => 'red',
                                        default => 'amber',
                                    };
                                    $pLabel = match($inv->payment_status) {
                                        'full' => 'Paid',
                                        'half' => 'Partial',
                                        'installment' => 'Installment',
                                        'discount' => 'Discount',
                                        default => 'Unpaid',
                                    };
                                    $pColor = match($inv->payment_status) {
                                        'full' => 'green',
                                        'half' => 'blue',
                                        'installment' => 'cyan',
                                        'discount' => 'purple',
                                        default => 'gray',
                                    };
                                @endphp
                                <tr class="{{ $inv->status === 'overdue' ? 'bg-red-50' : '' }}">
                                    <td class="font-medium">{{ $inv->client_name }}</td>
                                    <td class="text-sm text-gray-600">{{ $inv->description ?? '-' }}</td>
                                    <td class="text-right font-semibold">{{ fmtCurrency($inv->amount) }}</td>
                                    <td>
                                        <div class="flex items-center gap-1.5">
                                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-{{ $sColor }}-100 text-{{ $sColor }}-700">
                                                @if($inv->status === 'paid')<i class="fas fa-check-circle"></i>@elseif($inv->status === 'overdue')<i class="fas fa-exclamation-triangle"></i>@else<i class="fas fa-clock"></i>@endif
                                                {{ ucfirst($inv->status) }}
                                            </span>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-{{ $pColor }}-100 text-{{ $pColor }}-700">
                                                {{ $pLabel }}@if(!empty($inv->installment_progress)) ({{ $inv->installment_progress }})@endif
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-sm">{{ fmtDate($inv->due_date) }}</td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1.5">
                                            {{-- Primary action: contextual based on client/status --}}
                                            @if($this->isClient)
                                                <button wire:click="openDetail({{ $inv->id }})" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                                                    <i class="fas fa-eye text-[11px]"></i> View
                                                </button>
                                            @elseif($inv->status !== 'paid')
                                                <button wire:click="openRecordPayment({{ $inv->id }})" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-green-50 text-green-700 hover:bg-green-100 transition">
                                                    <i class="fas fa-money-bill-wave text-[11px]"></i> Record Payment
                                                </button>
                                            @else
                                                <button wire:click="openDetail({{ $inv->id }})" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                                                    <i class="fas fa-eye text-[11px]"></i> View
                                                </button>
                                            @endif

                                            {{-- Kebab menu with the rest --}}
                                            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                                                <button @click="open = !open" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition" aria-label="More actions">
                                                    <i class="fas fa-ellipsis-v text-sm"></i>
                                                </button>
                                                <div
                                                    x-show="open"
                                                    x-transition.origin.top.right
                                                    x-cloak
                                                    class="absolute right-0 z-20 mt-1 w-52 rounded-xl border border-gray-100 bg-white py-1.5 shadow-lg ring-1 ring-black/5"
                                                    style="display:none;"
                                                >
                                                    <button wire:click="openDetail({{ $inv->id }})" @click="open = false" class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                        <i class="fas fa-eye w-4 text-gray-400"></i> View details
                                                    </button>
                                                    @unless($this->isClient)
                                                        <button wire:click="openRecordPayment({{ $inv->id }})" @click="open = false" class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                            <i class="fas fa-money-bill-wave w-4 text-gray-400"></i> Record payment
                                                        </button>
                                                        @if($inv->status !== 'paid')
                                                            <button
                                                                type="button"
                                                                @click="open = false; $dispatch('open-confirm', { title: 'Mark as Paid?', message: 'Invoice #{{ $inv->id }} will be marked as paid and the remaining balance (NPR {{ number_format($inv->remaining, 2) }}) will be settled.', type: 'info', action: 'markAsPaid', params: [{{ $inv->id }}] })"
                                                                class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs font-medium text-gray-700 hover:bg-gray-50"
                                                            >
                                                                <i class="fas fa-check-circle w-4 text-gray-400"></i> Mark as paid
                                                            </button>
                                                        @endif
                                                    @endunless
                                                    <a href="{{ route('invoices.pdf', $inv->id) }}" target="_blank" @click="open = false" class="flex items-center gap-2.5 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                        <i class="fas fa-file-pdf w-4 text-gray-400"></i> Download PDF
                                                    </a>
                                                    @unless($this->isClient)
                                                        <div class="my-1 border-t border-gray-100"></div>
                                                        <button
                                                            type="button"
                                                            @click="open = false; $dispatch('open-confirm', { title: 'Delete Invoice?', message: 'This invoice will be permanently removed.', type: 'danger', action: 'deleteInvoice', params: [{{ $inv->id }}] })"
                                                            class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs font-medium text-red-600 hover:bg-red-50"
                                                        >
                                                            <i class="fas fa-trash w-4"></i> Delete invoice
                                                        </button>
                                                    @endunless
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr wire:loading.remove wire:target="search,statusFilter,clientFilter,activeTab"><td colspan="6" class="text-center py-8 text-gray-400">No invoices found</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- OUTSTANDING PAYMENTS SUMMARY --}}
            @if($activeTab === 'invoices' && !$this->isClient)
                @php $outstanding = $this->getOutstandingSummary(); @endphp
                @if($outstanding['count'] > 0)
                    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                        <div class="px-4 py-3 bg-amber-50 border-b border-amber-100 flex items-center justify-between">
                            <h3 class="text-sm font-bold text-amber-700"><i class="fas fa-exclamation-triangle mr-1.5"></i>Outstanding Payments ({{ $outstanding['count'] }} invoices)</h3>
                            <span class="text-sm font-bold text-amber-700">{{ fmtCurrency($outstanding['total']) }}</span>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                                <div class="bg-red-50 rounded-lg p-3">
                                    <div class="text-xs text-red-600 font-semibold mb-1"><i class="fas fa-clock mr-1"></i>Overdue</div>
                                    <div class="text-lg font-bold text-red-700">{{ fmtCurrency($outstanding['overdue']) }}</div>
                                    <div class="text-xs text-red-500">{{ $outstanding['overdue_count'] }} invoice(s)</div>
                                </div>
                                <div class="bg-amber-50 rounded-lg p-3">
                                    <div class="text-xs text-amber-600 font-semibold mb-1"><i class="fas fa-hourglass-half mr-1"></i>Pending</div>
                                    <div class="text-lg font-bold text-amber-700">{{ fmtCurrency($outstanding['pending']) }}</div>
                                    <div class="text-xs text-amber-500">{{ $outstanding['pending_count'] }} invoice(s)</div>
                                </div>
                                <div class="bg-cyan-50 rounded-lg p-3">
                                    <div class="text-xs text-cyan-600 font-semibold mb-1"><i class="fas fa-calendar-alt mr-1"></i>Installment</div>
                                    <div class="text-lg font-bold text-cyan-700">{{ fmtCurrency($outstanding['installment']) }}</div>
                                    <div class="text-xs text-cyan-500">{{ $outstanding['installment_count'] }} invoice(s)</div>
                                </div>
                            </div>
                            @if($outstanding['oldest_due'])
                                <div class="text-xs text-gray-500 text-center">Oldest unpaid invoice due on {{ fmtDate($outstanding['oldest_due']) }}</div>
                            @endif
                        </div>
                    </div>
                @endif
            @endif

            {{-- SALARY & WORKERS TAB --}}
            @if($activeTab === 'salary' && !$this->isClient)
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-indigo-100 text-indigo-600"><i class="fas fa-users"></i></div><div class="stat-value">{{ $this->salaryStats['member_count'] }}</div><div class="stat-label">Team Members</div></div>
                    <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-money-bill-wave"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['total_payroll']) }}</div><div class="stat-label">Total Payroll</div></div>
                    <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['paid']) }}</div><div class="stat-label">Paid</div></div>
                    <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-hourglass-half"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['pending']) }}</div><div class="stat-label">Pending</div></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['total_ot']) }}</div><div class="stat-label">Total OT Pay</div></div>
                    <div class="stat-card"><div class="stat-icon bg-teal-100 text-teal-600"><i class="fas fa-gift"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['total_bonus']) }}</div><div class="stat-label">Total Bonus</div></div>
                    <div class="stat-card"><div class="stat-icon bg-red-100 text-red-600"><i class="fas fa-minus-circle"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['total_deductions']) }}</div><div class="stat-label">Total Deductions</div></div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 text-center">
                    <div class="w-12 h-12 rounded-full bg-indigo-100 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-users-cog text-indigo-500 text-xl"></i>
                    </div>
                    <h3 class="font-bold text-gray-800 mb-1">Salary & Workers</h3>
                    <p class="text-sm text-gray-500 mb-4">Manage salaries, generate payslips, and track worker payments</p>
                    <a href="{{ route('salary') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[var(--brand)] text-white rounded-lg font-semibold text-sm hover:opacity-90 transition">
                        <i class="fas fa-external-link-alt"></i> Open Full Salary Page
                    </a>
                </div>
            @endif

            {{-- EXPENSES TAB --}}
            @if($activeTab === 'expenses' && !$this->isClient)
                @php
                    $totalExpenses = $this->stats['expenses'];
                    $topCategories = $this->expenseSummary->take(3);
                @endphp
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-orange-100 text-orange-600"><i class="fas fa-receipt"></i></div><div class="stat-value">{{ fmtCurrency($totalExpenses) }}</div><div class="stat-label">Total Expenses</div></div>
                    <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-layer-group"></i></div><div class="stat-value">{{ $this->expenseSummary->count() }}</div><div class="stat-label">Categories</div></div>
                    <div class="stat-card"><div class="stat-icon bg-red-100 text-red-600"><i class="fas fa-arrow-up"></i></div><div class="stat-value">{{ $topCategories->isNotEmpty() ? $topCategories->first()->category : '-' }}</div><div class="stat-label">Highest Category</div></div>
                    <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-chart-line"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['net_profit']) }}</div><div class="stat-label">Net Profit</div></div>
                </div>

                @if($topCategories->count())
                    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                        <div class="px-4 py-3 bg-gray-50 border-b border-gray-100">
                            <h3 class="text-sm font-bold text-gray-700"><i class="fas fa-chart-pie mr-1.5 text-gray-400"></i>Top Expense Categories — {{ now()->format('F Y') }}</h3>
                        </div>
                        <div class="p-4 space-y-3">
                            @foreach($topCategories as $cat)
                                @php
                                    $colors = ['salary'=>'red','office'=>'blue','operations'=>'gray','software'=>'purple','equipment'=>'amber','travel'=>'green','marketing'=>'pink','other'=>'slate'];
                                    $icons = ['salary'=>'fa-users','office'=>'fa-building','operations'=>'fa-cogs','software'=>'fa-code','equipment'=>'fa-laptop','travel'=>'fa-plane','marketing'=>'fa-bullhorn','other'=>'fa-ellipsis-h'];
                                    $color = $colors[$cat->category] ?? 'gray';
                                    $icon = $icons[$cat->category] ?? 'fa-circle';
                                    $pct = $totalExpenses > 0 ? round(($cat->total / $totalExpenses) * 100) : 0;
                                @endphp
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-lg bg-{{ $color }}-100 flex items-center justify-center shrink-0"><i class="fas {{ $icon }} text-{{ $color }}-500"></i></div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm font-medium text-gray-700 capitalize">{{ $cat->category }}</span>
                                            <span class="text-sm font-bold">{{ fmtCurrency($cat->total) }}</span>
                                        </div>
                                        <div class="mt-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                            <div class="h-full bg-{{ $color }}-400 rounded-full" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                    <span class="text-xs text-gray-400 w-14 text-right">{{ $pct }}%</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="bg-white rounded-xl border border-gray-200 p-6 text-center">
                    <div class="w-12 h-12 rounded-full bg-orange-100 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-receipt text-orange-500 text-xl"></i>
                    </div>
                    <h3 class="font-bold text-gray-800 mb-1">Expense Management</h3>
                    <p class="text-sm text-gray-500 mb-4">Track, categorize, and manage all business expenses</p>
                    <a href="{{ route('expenses') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[var(--brand)] text-white rounded-lg font-semibold text-sm hover:opacity-90 transition">
                        <i class="fas fa-external-link-alt"></i> Open Full Expenses Page
                    </a>
                </div>
            @endif

            {{-- OVERTIME TAB --}}
            @if($activeTab === 'overtime' && !$this->isClient)
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ number_format($this->overtimeSummary['total_hours'], 1) }}h</div><div class="stat-label">Total Hours</div></div>
                    <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ number_format($this->overtimeSummary['approved_hours'], 1) }}h</div><div class="stat-label">Approved</div></div>
                    <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-hourglass-half"></i></div><div class="stat-value">{{ $this->overtimeSummary['pending_count'] }}</div><div class="stat-label">Pending Approval</div></div>
                    <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-coins"></i></div><div class="stat-value">{{ fmtCurrency($this->overtimeSummary['total_cost']) }}</div><div class="stat-label">Total OT Cost</div></div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 text-center">
                    <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-clock text-blue-500 text-xl"></i>
                    </div>
                    <h3 class="font-bold text-gray-800 mb-1">Overtime Tracking</h3>
                    <p class="text-sm text-gray-500 mb-4">Log overtime hours, approve entries, and calculate OT pay</p>
                    <a href="{{ route('overtime') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[var(--brand)] text-white rounded-lg font-semibold text-sm hover:opacity-90 transition">
                        <i class="fas fa-external-link-alt"></i> Open Full Overtime Page
                    </a>
                </div>
            @endif

            {{-- Invoice Form Modal --}}
            @if($showInvoiceForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showInvoiceForm', false)" x-on:keydown.escape.window="$wire.set('showInvoiceForm', false)">
                    <div class="modal-box w-full max-w-lg mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingId ? 'Edit' : 'New' }} Invoice</h3>
                            <button wire:click="$set('showInvoiceForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4 max-h-[70vh] overflow-y-auto">
                            <div><label class="form-label">Client</label>
                                <select wire:model="formClientId" class="form-select"><option value="">Select Client</option>
                                    @foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                                </select>
                                <span wire:error="formClientId" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="form-label">Amount</label><input type="number" wire:model="formAmount" class="form-input" step="0.01"><span wire:error="formAmount" class="text-red-500 text-xs mt-1 block"></span></div>
                                <div><label class="form-label">Due Date</label><input type="date" wire:model="formDueDate" class="form-input"><span wire:error="formDueDate" class="text-red-500 text-xs mt-1 block"></span></div>
                            </div>
                            <div><label class="form-label">Description</label><input type="text" wire:model="formDescription" class="form-input"></div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="form-label">Status</label>
                                    <select wire:model="formStatus" class="form-select">
                                        <option value="pending">Pending</option><option value="paid">Paid</option><option value="overdue">Overdue</option>
                                    </select>
                                </div>
                                <div><label class="form-label">Payment Status</label>
                                    <select wire:model="formPaymentStatus" class="form-select">
                                        <option value="pending">Pending</option><option value="half">Half</option><option value="full">Full</option>
                                        <option value="installment">Installment</option><option value="discount">Discount</option>
                                    </select>
                                </div>
                            </div>
                            @if($formPaymentStatus === 'discount')
                                <div><label class="form-label">Discount Amount</label><input type="number" wire:model="formDiscountAmount" class="form-input" step="0.01"></div>
                            @endif
                            @if($formPaymentStatus === 'installment')
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <div><label class="form-label">Total</label><input type="number" wire:model="formTotalInstallments" class="form-input"></div>
                                    <div><label class="form-label">Paid</label><input type="number" wire:model="formPaidInstallments" class="form-input"></div>
                                    <div><label class="form-label">Per Installment</label><input type="number" wire:model="formAmountPerInstallment" class="form-input" step="0.01"></div>
                                </div>
                            @endif
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showInvoiceForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveInvoice" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveInvoice"><span wire:loading.remove wire:target="saveInvoice">Save</span><span wire:loading wire:target="saveInvoice" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Record Payment Modal --}}
            @if($showRecordPayment)
                @php $inv = $this->getPaymentInvoice(); $ctx = $this->getPaymentContext(); @endphp
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showRecordPayment', false)" x-on:keydown.escape.window="$wire.set('showRecordPayment', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">Record Payment</h3>
                            <button wire:click="$set('showRecordPayment', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        @if($inv)
                        <div class="p-4 space-y-4">
                            {{-- Invoice Summary --}}
                            <div class="bg-gray-50 rounded-lg p-3 text-sm space-y-1">
                                <div class="flex justify-between"><span>Invoice Total:</span><span class="font-semibold">{{ fmtCurrency($inv->amount) }}</span></div>
                                <div class="flex justify-between"><span>Discount:</span><span>{{ fmtCurrency($inv->discount_amount) }}</span></div>
                                <div class="flex justify-between"><span>Already Paid:</span><span class="text-green-600">{{ fmtCurrency($ctx['total_paid']) }}</span></div>
                                <div class="flex justify-between border-t border-gray-200 pt-1 mt-1"><span class="font-semibold">Remaining:</span><span class="font-bold text-red-600">{{ fmtCurrency($ctx['remaining']) }}</span></div>
                            </div>

                            {{-- Installment Progress --}}
                            @if($ctx['is_installment'])
                                <div class="bg-cyan-50 rounded-lg p-3 border border-cyan-200">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-bold text-cyan-700"><i class="fas fa-calendar-alt mr-1"></i>Installment {{ $ctx['current_installment_number'] }} of {{ $ctx['total_installments'] }}</span>
                                        <span class="text-xs text-cyan-600">{{ $ctx['paid_installments'] }} fully paid</span>
                                    </div>
                                    {{-- Installment blocks --}}
                                    <div class="flex gap-1 mb-2">
                                        @for($i = 0; $i < $ctx['total_installments']; $i++)
                                            <div class="flex-1 h-3 rounded {{ $i < $ctx['paid_installments'] ? 'bg-green-500' : ($i === $ctx['paid_installments'] ? 'bg-cyan-400' : 'bg-gray-300') }}"></div>
                                        @endfor
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-cyan-700">Per installment: <strong>{{ fmtCurrency($ctx['amount_per_installment']) }}</strong></span>
                                        @if($ctx['current_installment_remaining'] > 0 && $ctx['current_installment_paid'] > 0)
                                            <span class="text-amber-600">Partial paid: {{ fmtCurrency($ctx['current_installment_paid']) }}</span>
                                        @endif
                                    </div>
                                    @if($ctx['current_installment_remaining'] > 0)
                                        <div class="mt-2 bg-white rounded px-2 py-1.5 border border-cyan-200">
                                            <span class="text-xs text-gray-500">Expected this payment:</span>
                                            <span class="text-sm font-bold text-cyan-700 ml-1">{{ fmtCurrency($ctx['current_installment_remaining']) }}</span>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Amount Input --}}
                            <div>
                                <label class="form-label">Amount</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">NPR</span>
                                    <input type="number" wire:model="payAmount" class="form-input pl-12" step="0.01" placeholder="{{ number_format($ctx['expected_amount'], 2, '.', '') }}">
                                </div>
                                @if($ctx['is_installment'] && $ctx['expected_amount'] > 0)
                                    <button wire:click="$set('payAmount', {{ $ctx['expected_amount'] }})" class="mt-1 text-xs text-cyan-600 hover:text-cyan-800 underline">
                                        <i class="fas fa-magic mr-0.5"></i>Fill expected amount ({{ fmtCurrency($ctx['expected_amount']) }})
                                    </button>
                                @endif
                                <span wire:error="payAmount" class="text-red-500 text-xs mt-1 block"></span>
                            </div>

                            {{-- Date --}}
                            <div><label class="form-label">Date</label><input type="date" wire:model="payDate" class="form-input"><span wire:error="payDate" class="text-red-500 text-xs mt-1 block"></span></div>

                            {{-- Method --}}
                            <div><label class="form-label">Payment Method</label>
                                <select wire:model="payMethod" class="form-select">
                                    <option value="cash">💵 Cash</option>
                                    <option value="bank">🏦 Bank Transfer</option>
                                    <option value="card">💳 Card</option>
                                    <option value="cheque">📄 Cheque</option>
                                    <option value="esewa">📱 eSewa</option>
                                    <option value="khalti">📱 Khalti</option>
                                    <option value="other">📋 Other</option>
                                </select>
                            </div>

                            {{-- Note --}}
                            <div><label class="form-label">Note</label><input type="text" wire:model="payNote" class="form-input" placeholder="e.g. First installment, partial payment, etc."></div>

                            {{-- Proof --}}
                            <div>
                                <label class="form-label">Payment Proof (optional)</label>
                                <div class="border-2 border-dashed border-gray-200 rounded-lg p-3 text-center hover:border-[var(--brand)] transition-colors">
                                    <input type="file" wire:model="payProof" accept="image/*,.pdf" class="hidden" x-ref="proofInput">
                                    @if($payProof)
                                        <div class="flex items-center gap-2 justify-center">
                                            <i class="fas fa-file text-green-500"></i>
                                            <span class="text-sm text-green-600">{{ $payProof->getClientOriginalName() }}</span>
                                            <button type="button" wire:click="$set('payProof', null)" class="text-red-400 hover:text-red-600"><i class="fas fa-times"></i></button>
                                        </div>
                                    @else
                                        <button type="button" x-on:click="$refs.proofInput.click()" class="text-sm text-gray-500 hover:text-[var(--brand)]">
                                            <i class="fas fa-cloud-upload-alt mr-1"></i> Upload receipt / screenshot
                                        </button>
                                        <p class="text-[10px] text-gray-400 mt-1">JPG, PNG or PDF</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showRecordPayment', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="savePayment" class="btn btn-primary" wire:loading.attr="disabled" wire:target="savePayment"><span wire:loading.remove wire:target="savePayment">Save Payment</span><span wire:loading wire:target="savePayment" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button>
                        </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Invoice Detail Modal --}}
            @if($showDetail)
                @php $detail = $this->getDetailInvoice(); $payments = $this->getPayments($this->detailId); @endphp
                @if($detail)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showDetail', false)" x-on:keydown.escape.window="$wire.set('showDetail', false)">
                    <div class="modal-box w-full max-w-xl mx-4 print-invoice">
                        <div class="sticky top-0 bg-white flex items-center justify-between px-6 py-4 border-b modal-header">
                            <div>
                                <h3 class="font-bold text-lg text-gray-900">Invoice #{{ $detail->id }}</h3>
                                <p class="text-xs text-gray-500 mt-0.5">Issued {{ fmtDate($detail->created_at ?? $detail->due_date) }}</p>
                            </div>
                            <div class="flex items-center gap-1 no-print">
                                <button onclick="window.print()" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition" title="Print"><i class="fas fa-print text-sm"></i></button>
                                <button wire:click="$set('showDetail', false)" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition" title="Close"><i class="fas fa-times text-sm"></i></button>
                            </div>
                        </div>
                        <div class="px-6 py-5 space-y-5 max-h-[70vh] overflow-y-auto">
                            <div class="flex items-center gap-3">
                                <div class="flex-1">
                                    <h4 class="font-bold">{{ $detail->client_name }}</h4>
                                    <p class="text-sm text-gray-500">{{ $detail->description ?? 'No description' }}</p>
                                </div>
                                @php
                                    $statusColor = match($detail->status) {
                                        'paid' => 'green',
                                        'overdue' => 'red',
                                        default => 'amber',
                                    };
                                    $paymentLabel = match($detail->payment_status) {
                                        'full' => 'Fully Paid',
                                        'half' => 'Partially Paid',
                                        'installment' => 'Installment',
                                        'discount' => 'Discounted',
                                        default => 'Unpaid',
                                    };
                                    $paymentColor = match($detail->payment_status) {
                                        'full' => 'green',
                                        'half' => 'blue',
                                        'installment' => 'cyan',
                                        'discount' => 'purple',
                                        default => 'gray',
                                    };
                                @endphp
                                <div class="flex flex-col items-end gap-1">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-{{ $statusColor }}-100 text-{{ $statusColor }}-700">
                                        @if($detail->status === 'paid')<i class="fas fa-check-circle"></i>@elseif($detail->status === 'overdue')<i class="fas fa-exclamation-triangle"></i>@else<i class="fas fa-clock"></i>@endif
                                        {{ ucfirst($detail->status) }}
                                    </span>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-{{ $paymentColor }}-100 text-{{ $paymentColor }}-700">
                                        {{ $paymentLabel }}
                                    </span>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                <div class="bg-gray-50 rounded-lg px-3 py-2.5"><div class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-0.5">Amount</div><div class="font-bold text-gray-900">{{ fmtCurrency($detail->amount) }}</div></div>
                                <div class="bg-gray-50 rounded-lg px-3 py-2.5"><div class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-0.5">Discount</div><div class="font-semibold text-gray-700">{{ fmtCurrency($detail->discount_amount) }}</div></div>
                                <div class="bg-gray-50 rounded-lg px-3 py-2.5"><div class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-0.5">Due</div><div class="font-semibold text-gray-700">{{ fmtDate($detail->due_date) }}</div></div>
                                <div class="bg-gray-50 rounded-lg px-3 py-2.5"><div class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-0.5">Paid</div><div class="font-semibold text-gray-700">{{ $detail->paid_date ? fmtDate($detail->paid_date) : '—' }}</div></div>
                            </div>
                            @if($detail->installment_plan)
                                @php $plan = is_string($detail->installment_plan) ? json_decode($detail->installment_plan, true) : $detail->installment_plan; @endphp
                                <div class="bg-cyan-50 rounded-lg p-3">
                                    <h5 class="font-semibold text-sm mb-2">Installment Plan</h5>
                                    <div class="flex gap-1">
                                        @for($i = 0; $i < ($plan['totalInstallments'] ?? 0); $i++)
                                            <div class="w-6 h-6 rounded {{ $i < ($plan['paidInstallments'] ?? 0) ? 'bg-green-500' : 'bg-gray-300' }}"></div>
                                        @endfor
                                    </div>
                                    <p class="text-xs text-gray-600 mt-1">{{ fmtCurrency($plan['amountPerInstallment'] ?? 0) }} per installment</p>
                                </div>
                            @endif
                            <div>
                                <h5 class="font-semibold text-sm mb-2">Payment History</h5>
                                @forelse($payments as $i => $p)
                                    @php
                                        $methodIcons = ['cash' => 'fa-money-bill-wave', 'bank' => 'fa-university', 'card' => 'fa-credit-card', 'cheque' => 'fa-file-invoice', 'esewa' => 'fa-mobile-alt', 'khalti' => 'fa-mobile-alt', 'other' => 'fa-ellipsis-h'];
                                        $methodColors = ['cash' => 'green', 'bank' => 'blue', 'card' => 'purple', 'cheque' => 'amber', 'esewa' => 'cyan', 'khalti' => 'cyan', 'other' => 'gray'];
                                        $mIcon = $methodIcons[$p->method] ?? 'fa-circle';
                                        $mColor = $methodColors[$p->method] ?? 'gray';
                                    @endphp
                                    <div class="flex items-start justify-between text-sm py-2.5 {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-bold text-gray-900">{{ fmtCurrency($p->amount) }}</span>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-{{ $mColor }}-100 text-{{ $mColor }}-700">
                                                    <i class="fas {{ $mIcon }}"></i> {{ ucfirst($p->method) }}
                                                </span>
                                                @if($p->verified)
                                                    <span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full font-semibold"><i class="fas fa-check-circle mr-0.5"></i>Verified</span>
                                                @else
                                                    <button wire:click="verifyPayment({{ $p->id }})" class="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-semibold hover:bg-amber-200"><i class="fas fa-check mr-0.5"></i>Verify</button>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <span class="text-gray-400 text-xs"><i class="fas fa-calendar mr-0.5"></i>{{ fmtDate($p->date) }}</span>
                                                @if($p->note)
                                                    <span class="text-gray-500 text-xs"><i class="fas fa-comment mr-0.5"></i>{{ $p->note }}</span>
                                                @endif
                                                @if($p->verified_by)
                                                    <span class="text-gray-400 text-xs">· Verified by {{ $p->verified_by }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        @if($p->proof_path)
                                            <a href="{{ $this->getProofUrl($p->id) }}" target="_blank" class="text-blue-500 hover:text-blue-700 ml-2 mt-0.5" title="View proof"><i class="fas fa-paperclip"></i></a>
                                        @endif
                                    </div>
                                @empty
                                    <div class="text-center py-6">
                                        <i class="fas fa-receipt text-gray-300 text-2xl mb-2"></i>
                                        <p class="text-sm text-gray-400">No payments recorded yet</p>
                                        <p class="text-xs text-gray-300">Record a payment to start tracking</p>
                                    </div>
                                @endforelse
                            </div>
                            <div class="bg-gray-50 rounded-lg px-4 py-3 flex justify-between items-center">
                                <div><div class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">Total Paid</div><div class="font-bold text-gray-900 text-base">{{ fmtCurrency(DB::table('invoice_payments')->where('invoice_id', $detail->id)->sum('amount')) }}</div></div>
                                <div class="text-right"><div class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">Remaining</div><div class="font-bold text-red-600 text-base">{{ fmtCurrency($detail->amount - $detail->discount_amount - DB::table('invoice_payments')->where('invoice_id', $detail->id)->sum('amount')) }}</div></div>
                            </div>
                            <div class="bg-slate-50 rounded-lg px-3 py-2 text-center">
                                <p class="text-[10px] text-slate-400">⚡ System auto-generated invoice. Contact administration for original.</p>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-between items-center px-6 py-4 border-t no-print">
                            <a href="{{ route('invoices.pdf', $detail->id) }}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-red-50 text-red-600 rounded-lg font-semibold text-sm hover:bg-red-100 transition">
                                <i class="fas fa-file-pdf"></i> Download PDF
                            </a>
                            <button wire:click="$set('showDetail', false)" class="btn btn-secondary">Close</button>
                        </div>
                    </div>
                </div>
                @endif
            @endif
        </div>
        blade;
    }

    #[On('confirm-resolved')]
    public function onConfirmResolved(string $action, array $params = []): void
    {
        if ($action !== '' && method_exists($this, $action)) {
            $this->{$action}(...$params);
        }
    }
};
