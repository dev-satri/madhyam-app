<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Invoice;
use App\Services\PaymentTracker;
use App\Services\ActivityLogger;
use App\Services\NotificationService;

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
        if ($this->statusFilter) $q->where('invoices.status', $this->statusFilter);
        if ($this->clientFilter) $q->where('invoices.client_id', $this->clientFilter);

        $invoices = $q->select('invoices.*', 'clients.name as client_name')
            ->orderBy('invoices.due_date', 'desc')
            ->get();

        $invoiceIds = $invoices->pluck('id')->toArray();
        $payments = !empty($invoiceIds)
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
        if (!$owned) return collect();

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
        }

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

    public function savePayment(): void
    {
        abort_if($this->isClientPortal(), 403);
        $this->validate([
            'payAmount' => 'required|numeric|min:0.01',
            'payDate' => 'required|date',
            'payMethod' => 'required',
        ]);

        $proofPath = null;
        if ($this->payProof) {
            $proofPath = $this->payProof->store('payment-proofs', 'public');
        }

        DB::table('invoice_payments')->insert([
            'invoice_id' => $this->paymentInvoiceId,
            'amount' => $this->payAmount,
            'date' => $this->payDate,
            'method' => $this->payMethod,
            'note' => $this->payNote ?: null,
            'proof_path' => $proofPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Recalculate invoice status via the canonical service (plan §24.5).
        if ($invoice = Invoice::find($this->paymentInvoiceId)) {
            app(PaymentTracker::class)->recalc($invoice);
        }

        app(ActivityLogger::class)->record(
            Auth::user(),
            "Payment of {$this->payAmount} recorded for invoice #{$this->paymentInvoiceId}"
        );
        app(NotificationService::class)->sendNotification(
            text: "Payment of {$this->payAmount} recorded for invoice #{$this->paymentInvoiceId}",
            type: 'success',
            link: route('reports', absolute: false),
            forRole: 'admin',
        );

        $this->showRecordPayment = false;
        $this->dispatch('toast', message: 'Payment recorded', type: 'success');
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
        DB::table('invoices')->where('id', $id)->delete();
        app(ActivityLogger::class)->record(Auth::user(), "Deleted invoice #{$id}");
        $this->dispatch('toast', message: 'Invoice deleted', type: 'success');
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
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
        if (!$payment) return null;

        $owned = $this->scopeToClient(DB::table('invoices'))
            ->where('id', $payment->invoice_id)
            ->exists();
        if (!$owned) return null;

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

            {{-- Skeleton loader --}}
            <div wire:loading.delay class="space-y-4 p-6">
                <div class="h-8 bg-gray-200 rounded animate-pulse w-1/3"></div>
                <div class="h-4 bg-gray-200 rounded animate-pulse w-2/3"></div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
                    <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
                    <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
                    <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
                </div>
                <div class="h-64 bg-gray-200 rounded-2xl animate-pulse"></div>
            </div>

            {{-- Global Stats --}}
            <div wire:loading.remove.delay class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-dollar-sign"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['revenue']) }}</div><div class="stat-label">{{ $this->isClient ? 'Total Billed' : 'Total Revenue' }}</div></div>
                <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['paid']) }}</div><div class="stat-label">Paid</div></div>
                <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['pending']) }}</div><div class="stat-label">Pending</div></div>
                <div class="stat-card"><div class="stat-icon bg-red-100 text-red-600"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['overdue']) }}</div><div class="stat-label">Overdue</div></div>
            </div>

            @unless($this->isClient)
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-orange-100 text-orange-600"><i class="fas fa-receipt"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['expenses']) }}</div><div class="stat-label">Total Expenses</div></div>
                    <div class="stat-card"><div class="stat-icon {{ $this->stats['net_profit'] >= 0 ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' }}"><i class="fas fa-chart-line"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['net_profit']) }}</div><div class="stat-label">Net Profit</div></div>
                    <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-percentage"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['discount']) }}</div><div class="stat-label">Discount Given</div></div>
                    <div class="stat-card"><div class="stat-icon bg-cyan-100 text-cyan-600"><i class="fas fa-calendar-alt"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['installment_due']) }}</div><div class="stat-label">Installment Due</div></div>
                </div>
            @endunless

            {{-- Tabs --}}
            @unless($this->isClient)
                <div class="border-b border-gray-200">
                    <nav class="flex gap-0 -mb-px">
                        <button wire:click="$set('activeTab', 'invoices')" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors {{ $activeTab === 'invoices' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"><i class="fas fa-file-invoice mr-1.5"></i>Invoices</button>
                        <button wire:click="$set('activeTab', 'salary')" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors {{ $activeTab === 'salary' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"><i class="fas fa-money-bill-wave mr-1.5"></i>Salary & Workers</button>
                        <button wire:click="$set('activeTab', 'expenses')" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors {{ $activeTab === 'expenses' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"><i class="fas fa-receipt mr-1.5"></i>Expenses</button>
                        <button wire:click="$set('activeTab', 'overtime')" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors {{ $activeTab === 'overtime' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"><i class="fas fa-clock mr-1.5"></i>Overtime</button>
                    </nav>
                </div>
            @endunless

            {{-- INVOICES TAB --}}
            @if($activeTab === 'invoices' || $this->isClient)
                {{-- Filters --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end no-print">
                    <div><label class="form-label">Search</label><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="text" wire:model.live.debounce.250ms="search" placeholder="Search invoices..." class="form-input pl-10 focus:ring-0"></div></div>
                    <div><label class="form-label">Status</label><select wire:model="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="overdue">Overdue</option>
                    </select></div>
                    <div><label class="form-label">Client</label><select wire:model="clientFilter" class="form-select">
                        <option value="">All Clients</option>
                        @foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select></div>
                </div>

                <div class="overflow-x-auto" wire:loading.target="search,statusFilter,clientFilter">
                    <div wire:loading class="p-6 space-y-3">
                        @for($i = 0; $i < 5; $i++)
                            <div class="skeleton-row"><div class="skeleton" style="width:100px;height:12px"></div><div class="skeleton" style="width:140px;height:12px"></div><div class="skeleton" style="width:70px;height:12px"></div><div class="skeleton" style="width:60px;height:12px"></div></div>
                        @endfor
                    </div>
                    <div wire:loading.remove wire:target="search,statusFilter,clientFilter">
                    <table class="data-table w-full">
                        <thead><tr>
                            <th>Client</th><th>Description</th><th>Amount</th><th>Status</th><th>Payment</th><th>Due Date</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                            @forelse($this->invoices as $inv)
                                <tr class="{{ $inv->status === 'overdue' ? 'bg-red-50' : '' }}">
                                    <td class="font-medium">{{ $inv->client_name }}</td>
                                    <td class="text-sm text-gray-600">{{ $inv->description ?? '-' }}</td>
                                    <td class="text-right font-semibold">{{ fmtCurrency($inv->amount) }}</td>
                                    <td><span class="badge {{ $inv->status === 'paid' ? 'badge-success' : ($inv->status === 'overdue' ? 'badge-danger' : 'badge-warning') }}">{{ ucfirst($inv->status) }}</span></td>
                                    <td>
                                        <span class="badge {{ $inv->payment_status === 'full' ? 'badge-success' : ($inv->payment_status === 'half' ? 'badge-warning' : 'badge-gray') }}">
                                            {{ ucfirst($inv->payment_status) }}
                                            @if(!empty($inv->installment_progress)) ({{ $inv->installment_progress }}) @endif
                                        </span>
                                    </td>
                                    <td class="text-sm">{{ fmtDate($inv->due_date) }}</td>
                                    <td>
                                        <div class="flex items-center gap-1">
                                            @unless($this->isClient)
                                                <button wire:click="openRecordPayment({{ $inv->id }})" class="btn btn-icon btn-ghost" title="Record Payment"><i class="fas fa-money-bill-wave text-gray-400 hover:text-green-500 text-xs"></i></button>
                                            @endunless
                                            <button wire:click="openDetail({{ $inv->id }})" class="btn btn-icon btn-ghost" title="View Details"><i class="fas fa-eye text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                            @unless($this->isClient)
                                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Invoice?', message: 'This invoice will be permanently removed.', type: 'danger', action: 'deleteInvoice', params: [{{ $inv->id }}] })" class="btn btn-icon btn-ghost" aria-label="Delete invoice" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                            @endunless
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center py-8 text-gray-400">No invoices found</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @endif

            {{-- SALARY & WORKERS TAB --}}
            @if($activeTab === 'salary' && !$this->isClient)
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-indigo-100 text-indigo-600"><i class="fas fa-users"></i></div><div class="stat-value">{{ $this->salaryStats['member_count'] }}</div><div class="stat-label">Team Members</div></div>
                    <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-money-bill-wave"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['total_payroll']) }}</div><div class="stat-label">Total Payroll</div></div>
                    <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['paid']) }}</div><div class="stat-label">Paid</div></div>
                    <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-hourglass-half"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['pending']) }}</div><div class="stat-label">Pending</div></div>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['total_ot']) }}</div><div class="stat-label">Total OT Pay</div></div>
                    <div class="stat-card"><div class="stat-icon bg-teal-100 text-teal-600"><i class="fas fa-gift"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['total_bonus']) }}</div><div class="stat-label">Total Bonus</div></div>
                    <div class="stat-card"><div class="stat-icon bg-red-100 text-red-600"><i class="fas fa-minus-circle"></i></div><div class="stat-value">{{ fmtCurrency($this->salaryStats['total_deductions']) }}</div><div class="stat-label">Total Deductions</div></div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-100">
                        <h3 class="text-sm font-bold text-gray-700"><i class="fas fa-users-cog mr-1.5 text-gray-400"></i>Worker Overview — {{ now()->format('F Y') }}</h3>
                    </div>
                    <table class="data-table w-full">
                        <thead><tr><th>Staff</th><th>Role</th><th>Base</th><th>OT Pay</th><th>Bonus</th><th>Deduction</th><th>Net Salary</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse($this->workerOverview as $w)
                                <tr>
                                    <td class="font-medium"><div class="flex items-center gap-2"><div class="w-7 h-7 rounded-full bg-[var(--brand)] text-white flex items-center justify-center text-[10px] font-bold">{{ substr($w->member_name, 0, 2) }}</div><div><div class="text-sm">{{ $w->member_name }}</div><div class="text-[10px] text-gray-400">{{ $w->dept_name ?? 'General' }}</div></div></div></td>
                                    <td class="text-xs text-gray-500">{{ ucfirst(str_replace('-', ' ', $w->member_role)) }}</td>
                                    <td class="text-sm font-mono">{{ fmtCurrency($w->base_salary) }}</td>
                                    <td class="text-sm font-mono text-purple-600">{{ fmtCurrency($w->overtime_pay) }}</td>
                                    <td class="text-sm font-mono text-green-600">{{ fmtCurrency($w->bonus) }}</td>
                                    <td class="text-sm font-mono text-red-600">{{ $w->leave_deduction > 0 ? '-'.fmtCurrency($w->leave_deduction) : '—' }}</td>
                                    <td class="text-sm font-bold">{{ fmtCurrency($w->net_salary) }}</td>
                                    <td><span class="badge {{ $w->status === 'paid' ? 'badge-success' : ($w->status === 'approved' ? 'badge-info' : 'badge-warning') }}">{{ ucfirst($w->status) }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center py-8 text-gray-400">No salary records for this month. Click "Ensure Records" on the <a href="{{ route('salary') }}" class="text-[var(--brand)] underline">Salary page</a> to generate.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- EXPENSES TAB --}}
            @if($activeTab === 'expenses' && !$this->isClient)
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-100">
                        <h3 class="text-sm font-bold text-gray-700"><i class="fas fa-receipt mr-1.5 text-gray-400"></i>Expense Breakdown — {{ now()->format('F Y') }}</h3>
                    </div>
                    <div class="p-4">
                        @forelse($this->expenseSummary as $cat)
                            @php
                                $colors = ['salary'=>'red','office'=>'blue','operations'=>'gray','software'=>'purple','equipment'=>'amber','travel'=>'green','marketing'=>'pink','other'=>'slate'];
                                $icons = ['salary'=>'fa-users','office'=>'fa-building','operations'=>'fa-cogs','software'=>'fa-code','equipment'=>'fa-laptop','travel'=>'fa-plane','marketing'=>'fa-bullhorn','other'=>'fa-ellipsis-h'];
                                $color = $colors[$cat->category] ?? 'gray';
                                $icon = $icons[$cat->category] ?? 'fa-circle';
                            @endphp
                            <div class="flex items-center gap-3 py-2.5 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                                <div class="w-8 h-8 rounded-lg bg-{{ $color }}-100 flex items-center justify-center"><i class="fas {{ $icon }} text-{{ $color }}-500 text-sm"></i></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-gray-700 capitalize">{{ $cat->category }}</span>
                                        <span class="text-sm font-bold">{{ fmtCurrency($cat->total) }}</span>
                                    </div>
                                    <div class="mt-1 h-1.5 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-{{ $color }}-400 rounded-full" style="width: {{ $this->stats['expenses'] > 0 ? round(($cat->total / $this->stats['expenses']) * 100) : 0 }}%"></div></div>
                                </div>
                                <span class="text-xs text-gray-400 w-12 text-right">{{ $cat->count }} items</span>
                            </div>
                        @empty
                            <p class="text-center text-gray-400 py-8">No expenses this month</p>
                        @endforelse
                    </div>
                </div>
                <p class="text-xs text-gray-400 text-center">For full expense management, visit the <a href="{{ route('expenses') }}" class="text-[var(--brand)] underline">Expenses page</a>.</p>
            @endif

            {{-- OVERTIME TAB --}}
            @if($activeTab === 'overtime' && !$this->isClient)
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ number_format($this->overtimeSummary['total_hours'], 1) }}h</div><div class="stat-label">Total Hours</div></div>
                    <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ number_format($this->overtimeSummary['approved_hours'], 1) }}h</div><div class="stat-label">Approved</div></div>
                    <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-hourglass-half"></i></div><div class="stat-value">{{ $this->overtimeSummary['pending_count'] }}</div><div class="stat-label">Pending Approval</div></div>
                    <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-coins"></i></div><div class="stat-value">{{ fmtCurrency($this->overtimeSummary['total_cost']) }}</div><div class="stat-label">Total OT Cost</div></div>
                </div>
                <p class="text-xs text-gray-400 text-center">For full overtime management, visit the <a href="{{ route('overtime') }}" class="text-[var(--brand)] underline">Overtime page</a>.</p>
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
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="form-label">Amount</label><input type="number" wire:model="formAmount" class="form-input" step="0.01"><span wire:error="formAmount" class="text-red-500 text-xs mt-1 block"></span></div>
                                <div><label class="form-label">Due Date</label><input type="date" wire:model="formDueDate" class="form-input"><span wire:error="formDueDate" class="text-red-500 text-xs mt-1 block"></span></div>
                            </div>
                            <div><label class="form-label">Description</label><input type="text" wire:model="formDescription" class="form-input"></div>
                            <div class="grid grid-cols-2 gap-3">
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
                                <div class="grid grid-cols-3 gap-3">
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
                @php $inv = $this->getPaymentInvoice(); @endphp
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showRecordPayment', false)" x-on:keydown.escape.window="$wire.set('showRecordPayment', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">Record Payment</h3>
                            <button wire:click="$set('showRecordPayment', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        @if($inv)
                        <div class="p-4 space-y-4">
                            <div class="bg-gray-50 rounded-lg p-3 text-sm space-y-1">
                                <div class="flex justify-between"><span>Invoice Total:</span><span class="font-semibold">{{ fmtCurrency($inv->amount) }}</span></div>
                                <div class="flex justify-between"><span>Discount:</span><span>{{ fmtCurrency($inv->discount_amount) }}</span></div>
                                <div class="flex justify-between"><span>Remaining:</span><span class="font-semibold text-red-600">{{ fmtCurrency($inv->amount - $inv->discount_amount - DB::table('invoice_payments')->where('invoice_id', $inv->id)->sum('amount')) }}</span></div>
                            </div>
                            <div><label class="form-label">Amount</label><input type="number" wire:model="payAmount" class="form-input" step="0.01"><span wire:error="payAmount" class="text-red-500 text-xs mt-1 block"></span></div>
                            <div><label class="form-label">Date</label><input type="date" wire:model="payDate" class="form-input"><span wire:error="payDate" class="text-red-500 text-xs mt-1 block"></span></div>
                            <div><label class="form-label">Method</label>
                                <select wire:model="payMethod" class="form-select">
                                    <option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="card">Card</option>
                                    <option value="cheque">Cheque</option><option value="esewa">eSewa</option><option value="khalti">Khalti</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div><label class="form-label">Note</label><input type="text" wire:model="payNote" class="form-input" placeholder="Optional note"></div>
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
                    <div class="modal-box w-full max-w-lg mx-4 print-invoice">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b modal-header">
                            <h3 class="font-bold text-lg">Invoice Details</h3>
                            <div class="flex items-center gap-2">
                                <button onclick="window.print()" class="text-gray-400 hover:text-gray-600 no-print" title="Print Invoice"><i class="fas fa-print"></i></button>
                                <button wire:click="$set('showDetail', false)" class="text-gray-400 hover:text-gray-600 no-print"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                        <div class="p-4 space-y-4 max-h-[70vh] overflow-y-auto">
                            <div class="flex items-center gap-3">
                                <div><h4 class="font-bold">{{ $detail->client_name }}</h4><p class="text-sm text-gray-500">{{ $detail->description ?? 'No description' }}</p></div>
                                <span class="badge {{ $detail->status === 'paid' ? 'badge-success' : ($detail->status === 'overdue' ? 'badge-danger' : 'badge-warning') }}">{{ ucfirst($detail->status) }}</span>
                                <span class="badge {{ $detail->payment_status === 'full' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($detail->payment_status) }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div class="bg-gray-50 rounded p-2"><span class="text-gray-500">Amount:</span> <span class="font-bold">{{ fmtCurrency($detail->amount) }}</span></div>
                                <div class="bg-gray-50 rounded p-2"><span class="text-gray-500">Discount:</span> {{ fmtCurrency($detail->discount_amount) }}</div>
                                <div class="bg-gray-50 rounded p-2"><span class="text-gray-500">Due:</span> {{ fmtDate($detail->due_date) }}</div>
                                <div class="bg-gray-50 rounded p-2"><span class="text-gray-500">Paid:</span> {{ $detail->paid_date ? fmtDate($detail->paid_date) : '-' }}</div>
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
                                    <div class="flex items-center justify-between text-sm py-2 border-b">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">#{{ $i + 1 }}</span> — {{ fmtCurrency($p->amount) }} via {{ ucfirst($p->method) }}
                                                @if($p->verified)
                                                    <span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full font-semibold"><i class="fas fa-check-circle mr-0.5"></i>Verified</span>
                                                @else
                                                    <button wire:click="verifyPayment({{ $p->id }})" class="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-semibold hover:bg-amber-200"><i class="fas fa-check mr-0.5"></i>Verify</button>
                                                @endif
                                            </div>
                                            <span class="text-gray-500 text-xs">{{ fmtDate($p->date) }}{{ $p->note ? ' — '.$p->note : '' }}{{ $p->verified_by ? ' · Verified by '.$p->verified_by : '' }}</span>
                                        </div>
                                        @if($p->proof_path)
                                            <a href="{{ $this->getProofUrl($p->id) }}" target="_blank" class="text-blue-500 hover:text-blue-700 ml-2" title="View proof"><i class="fas fa-paperclip"></i></a>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-400">No payments recorded</p>
                                @endforelse
                            </div>
                            <div class="bg-gray-50 rounded-lg p-3 flex justify-between">
                                <div><span class="text-gray-500 text-sm">Total Paid:</span> <span class="font-bold">{{ fmtCurrency(DB::table('invoice_payments')->where('invoice_id', $detail->id)->sum('amount')) }}</span></div>
                                <div><span class="text-gray-500 text-sm">Remaining:</span> <span class="font-bold text-red-600">{{ fmtCurrency($detail->amount - $detail->discount_amount - DB::table('invoice_payments')->where('invoice_id', $detail->id)->sum('amount')) }}</span></div>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end p-4 border-t">
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
