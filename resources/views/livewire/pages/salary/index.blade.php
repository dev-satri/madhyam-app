<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Services\SalaryCalculator;

new #[Layout('components.layouts.app')] class extends Component
{
    use \Livewire\WithFileUploads;
    use WithPagination;

    public string $search = '';
    public int $monthFilter = 0;
    public int $yearFilter = 0;
    public string $roleFilter = '';
    public int $deptFilter = 0;
    public string $statusFilter = '';
    public bool $showBaseEdit = false;
    public bool $showBonusEdit = false;
    public bool $showOtDetail = false;
    public bool $showLeaveDetail = false;
    public bool $showBreakdown = false;
    public bool $showPayConfirm = false;
    public bool $showBulkPayConfirm = false;
    public int $selectedSalaryId = 0;
    public float $editBaseSalary = 0;
    public float $editBonus = 0;
    public float $editDeduction = 0;
    public array $selectedIds = [];
    public bool $selectAll = false;

    protected SalaryCalculator $calculator;

    public function boot(): void
    {
        $this->calculator = app(SalaryCalculator::class);
    }

    public function mount(): void
    {
        $this->monthFilter = (int) now()->month;
        $this->yearFilter = (int) now()->year;
        $this->syncSalaryRecords();
    }

    public function updatedMonthFilter(): void
    {
        $this->syncSalaryRecords();
    }

    public function updatedYearFilter(): void
    {
        $this->syncSalaryRecords();
    }

    protected function syncSalaryRecords(): void
    {
        if (!$this->isManager()) return;
        $activeUsers = DB::table('users')->where('status', 'active')->get();
        foreach ($activeUsers as $u) {
            $model = \App\Models\User::find($u->id);
            if ($model) {
                $salary = $this->calculator->ensureFor($model, $this->monthFilter, $this->yearFilter);
                $this->calculator->recalc($salary);
            }
        }
    }

    public function isManager(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        return in_array($user->role, ['super-admin', 'admin', 'manager']);
    }

    public function getStats(): array
    {
        $q = DB::table('salaries')
            ->where('month', $this->monthFilter)
            ->where('year', $this->yearFilter);

        if (!$this->isManager()) {
            $q->where('member_id', Auth::id());
        }

        $records = (clone $q)->get();

        return [
            'total_payroll' => $records->sum('net_salary'),
            'paid' => (clone $q)->where('status', 'paid')->sum('net_salary'),
            'pending' => (clone $q)->where('status', 'pending')->sum('net_salary'),
            'average' => $records->count() > 0 ? $records->avg('net_salary') : 0,
            'total_bonus' => $records->sum('bonus'),
        ];
    }

    public function getSalaries()
    {
        $q = DB::table('salaries')
            ->join('users', 'salaries.member_id', '=', 'users.id')
            ->leftJoin('departments', 'users.department_id', '=', 'departments.id')
            ->where('salaries.month', $this->monthFilter)
            ->where('salaries.year', $this->yearFilter);

        if (!$this->isManager()) {
            $q->where('salaries.member_id', Auth::id());
        }

        if ($this->search) {
            $q->where('users.name', 'like', "%{$this->search}%");
        }

        if ($this->roleFilter) {
            $q->where('users.role', $this->roleFilter);
        }

        if ($this->deptFilter) {
            $q->where('users.department_id', $this->deptFilter);
        }

        if ($this->statusFilter) {
            $q->where('salaries.status', $this->statusFilter);
        }

        return $q->select(
                'salaries.*',
                'users.name as member_name',
                'users.role as member_role',
                'users.avatar',
                'departments.name as dept_name'
            )
            ->orderBy('users.name')
            ->paginate(50);
    }

    public function getSelectedSalary()
    {
        if (!$this->selectedSalaryId) return null;
        return DB::table('salaries')
            ->join('users', 'salaries.member_id', '=', 'users.id')
            ->where('salaries.id', $this->selectedSalaryId)
            ->select('salaries.*', 'users.name as member_name', 'users.role as member_role')
            ->first();
    }

    public function openBaseEdit(int $id): void
    {
        $salary = DB::table('salaries')->where('id', $id)->first();
        if ($salary) {
            $this->selectedSalaryId = $id;
            $this->editBaseSalary = (float) $salary->base_salary;
            $this->showBaseEdit = true;
        }
    }

    public function saveBaseEdit(): void
    {
        $this->validate(['editBaseSalary' => 'required|numeric|min:0']);
        $salary = DB::table('salaries')->where('id', $this->selectedSalaryId)->first();
        if ($salary) {
            DB::table('salaries')->where('id', $this->selectedSalaryId)->update([
                'base_salary' => $this->editBaseSalary,
                'updated_at' => now(),
            ]);
            // Recalc using service
            $model = \App\Models\Salary::find($this->selectedSalaryId);
            if ($model) $this->calculator->recalc($model);
            $this->showBaseEdit = false;
            $this->dispatch('toast', message: 'Base salary updated', type: 'success');
        }
    }

    public function openBonusEdit(int $id): void
    {
        $salary = DB::table('salaries')->where('id', $id)->first();
        if ($salary) {
            $this->selectedSalaryId = $id;
            $this->editBonus = (float) $salary->bonus;
            $this->showBonusEdit = true;
        }
    }

    public function saveBonusEdit(): void
    {
        $this->validate(['editBonus' => 'required|numeric|min:0']);
        $salary = DB::table('salaries')->where('id', $this->selectedSalaryId)->first();
        if ($salary) {
            DB::table('salaries')->where('id', $this->selectedSalaryId)->update([
                'bonus' => $this->editBonus,
                'updated_at' => now(),
            ]);
            $model = \App\Models\Salary::find($this->selectedSalaryId);
            if ($model) $this->calculator->recalc($model);
            $this->showBonusEdit = false;
            $this->dispatch('toast', message: 'Bonus updated', type: 'success');
        }
    }

    public function openOtDetail(int $id): void
    {
        $this->selectedSalaryId = $id;
        $this->showOtDetail = true;
    }

    public function openLeaveDetail(int $id): void
    {
        $this->selectedSalaryId = $id;
        $this->showLeaveDetail = true;
    }

    public function openBreakdown(int $id): void
    {
        $this->selectedSalaryId = $id;
        $this->showBreakdown = true;
    }

    public function cycleStatus(int $id): void
    {
        if (!$this->isManager()) return;
        $salary = DB::table('salaries')->where('id', $id)->first();
        if ($salary) {
            $next = match($salary->status) {
                'pending' => 'paid',
                'paid' => 'approved',
                'approved' => 'pending',
                default => 'pending',
            };
            DB::table('salaries')->where('id', $id)->update(['status' => $next, 'updated_at' => now()]);
            // Toggle OT paid status based on new salary status
            $otPaid = ($next === 'paid' || $next === 'approved');
            $start = \Carbon\Carbon::createFromDate($salary->year, $salary->month, 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            DB::table('overtime_logs')
                ->where('member_id', $salary->member_id)
                ->where('approved', true)
                ->whereBetween('date', [$start, $end])
                ->update(['paid' => $otPaid, 'updated_at' => now()]);
            // Recalc to keep OT/leaves in sync
            $model = \App\Models\Salary::find($id);
            if ($model) $this->calculator->recalc($model);
            $this->dispatch('toast', message: "Status changed to $next", type: 'success');
        }
    }

    public function openPayConfirm(int $id): void
    {
        $this->selectedSalaryId = $id;
        $this->showPayConfirm = true;
    }

    public function confirmPay(): void
    {
        if (!$this->isManager()) return;
        DB::table('salaries')->where('id', $this->selectedSalaryId)->update(['status' => 'paid', 'updated_at' => now()]);
        // Mark associated overtime logs as paid
        $salary = DB::table('salaries')->where('id', $this->selectedSalaryId)->first();
        if ($salary) {
            $start = \Carbon\Carbon::createFromDate($salary->year, $salary->month, 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            DB::table('overtime_logs')
                ->where('member_id', $salary->member_id)
                ->where('approved', true)
                ->where('paid', false)
                ->whereBetween('date', [$start, $end])
                ->update(['paid' => true, 'updated_at' => now()]);
        }
        $this->showPayConfirm = false;
        $this->dispatch('toast', message: 'Salary marked as paid', type: 'success');
    }

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedIds = [];
            $this->selectAll = false;
        } else {
            $salaries = $this->getSalaries()->items();
            $this->selectedIds = array_column($salaries, 'id');
            $this->selectAll = true;
        }
    }

    public function toggleSelect(int $id): void
    {
        if (in_array($id, $this->selectedIds)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$id]));
        } else {
            $this->selectedIds[] = $id;
        }
    }

    public function openBulkPayConfirm(): void
    {
        if (empty($this->selectedIds)) {
            $this->dispatch('toast', message: 'No salaries selected', type: 'error');
            return;
        }
        $this->showBulkPayConfirm = true;
    }

    public function confirmBulkPay(): void
    {
        if (!$this->isManager() || empty($this->selectedIds)) return;
        $salaries = DB::table('salaries')->whereIn('id', $this->selectedIds)->where('status', '!=', 'paid')->get();
        DB::table('salaries')->whereIn('id', $this->selectedIds)->where('status', 'pending')->update(['status' => 'paid', 'updated_at' => now()]);
        // Mark associated overtime logs as paid for each salary
        foreach ($salaries as $salary) {
            $start = \Carbon\Carbon::createFromDate($salary->year, $salary->month, 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            DB::table('overtime_logs')
                ->where('member_id', $salary->member_id)
                ->where('approved', true)
                ->where('paid', false)
                ->whereBetween('date', [$start, $end])
                ->update(['paid' => true, 'updated_at' => now()]);
        }
        $count = count($this->selectedIds);
        $this->selectedIds = [];
        $this->selectAll = false;
        $this->showBulkPayConfirm = false;
        $this->dispatch('toast', message: "$count salary record(s) marked as paid", type: 'success');
    }

    #[Computed]
    public function paySalaryDetail()
    {
        if (!$this->selectedSalaryId) return null;
        return DB::table('salaries')
            ->join('users', 'salaries.member_id', '=', 'users.id')
            ->where('salaries.id', $this->selectedSalaryId)
            ->select('salaries.*', 'users.name as member_name', 'users.email as member_email')
            ->first();
    }

    #[Computed]
    public function bulkPayDetails(): array
    {
        if (empty($this->selectedIds)) return ['items' => [], 'total' => 0, 'count' => 0];
        $items = DB::table('salaries')
            ->join('users', 'salaries.member_id', '=', 'users.id')
            ->whereIn('salaries.id', $this->selectedIds)
            ->where('salaries.status', 'pending')
            ->select('salaries.*', 'users.name as member_name')
            ->orderBy('users.name')
            ->get();
        return [
            'items' => $items,
            'total' => $items->sum('net_salary'),
            'count' => $items->count(),
        ];
    }

    public function deleteSalary(int $id): void
    {
        if (!$this->isManager()) return;
        DB::table('salaries')->where('id', $id)->delete();
        $this->dispatch('toast', message: 'Salary record deleted', type: 'success');
    }

    public function printSlip(int $id): void
    {
        $this->selectedSalaryId = $id;
        $this->dispatch('open-print-slip', salaryId: $id);
    }

    public function createSalaryRecords(): void
    {
        if (!$this->isManager()) return;
        $activeUsers = DB::table('users')->where('status', 'active')->get();
        $count = 0;
        foreach ($activeUsers as $u) {
            $model = \App\Models\User::find($u->id);
            if ($model) {
                $salary = $this->calculator->ensureFor($model, $this->monthFilter, $this->yearFilter);
                // Always recalc to pick up any new OT, leaves, etc.
                $this->calculator->recalc($salary);
                $count++;
            }
        }
        $this->dispatch('toast', message: "Salary records synced for $count staff", type: 'success');
    }

    public function getOtLogs()
    {
        $salary = $this->getSelectedSalary();
        if (!$salary) return collect();
        $start = \Carbon\Carbon::createFromDate($salary->year, $salary->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        return DB::table('overtime_logs')
            ->join('users', 'overtime_logs.member_id', '=', 'users.id')
            ->where('overtime_logs.member_id', $salary->member_id)
            ->whereBetween('overtime_logs.date', [$start, $end])
            ->where('overtime_logs.approved', true)
            ->select('overtime_logs.*', 'users.name as member_name')
            ->orderBy('overtime_logs.date')
            ->get();
    }

    public function markOtPaid(int $otId): void
    {
        if (!$this->isManager()) return;
        DB::table('overtime_logs')->where('id', $otId)->update(['paid' => true, 'updated_at' => now()]);
        $this->dispatch('toast', message: 'Overtime marked as paid', type: 'success');
    }

    public function markAllOtPaid(): void
    {
        if (!$this->isManager()) return;
        $salary = $this->getSelectedSalary();
        if (!$salary) return;
        $start = \Carbon\Carbon::createFromDate($salary->year, $salary->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $count = DB::table('overtime_logs')
            ->where('member_id', $salary->member_id)
            ->where('approved', true)
            ->where('paid', false)
            ->whereBetween('date', [$start, $end])
            ->update(['paid' => true, 'updated_at' => now()]);
        $this->dispatch('toast', message: "$count overtime log(s) marked as paid", type: 'success');
    }

    public function getLeaveLogs()
    {
        $salary = $this->getSelectedSalary();
        if (!$salary) return collect();
        $start = \Carbon\Carbon::createFromDate($salary->year, $salary->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        return DB::table('leaves')
            ->join('users', 'leaves.member_id', '=', 'users.id')
            ->where('leaves.member_id', $salary->member_id)
            ->where('leaves.status', 'approved')
            ->whereDate('leaves.start_date', '<=', $end)
            ->whereDate('leaves.end_date', '>=', $start)
            ->select('leaves.*', 'users.name as member_name')
            ->orderBy('leaves.start_date')
            ->get();
    }

    #[Computed]
    public function stats(): array { return $this->getStats(); }

    #[Computed]
    public function salaries() { return $this->getSalaries(); }

    #[Computed]
    public function selectedSalary() { return $this->getSelectedSalary(); }

    #[Computed]
    public function otLogs() { return $this->getOtLogs(); }

    #[Computed]
    public function leaveLogs() { return $this->getLeaveLogs(); }

    #[Computed]
    public function isMgr(): bool { return $this->isManager(); }

    #[Computed]
    public function departments()
    {
        return DB::table('departments')->orderBy('name')->get();
    }

    #[Computed]
    public function baseDefault(): float
    {
        return (float) (DB::table('settings')->value('base_salary_default') ?? 25000);
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Salary</h1>
                    <p class="text-sm text-gray-500 mt-1">Manage salary payments and slips</p>
                </div>
                @if($this->isMgr)
                    <button wire:click="createSalaryRecords" class="btn btn-secondary btn-sm"><i class="fas fa-magic text-sm"></i> Ensure Records</button>
                @endif
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-money-check-alt"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['total_payroll']) }}</div><div class="stat-label">Total Payroll</div></div>
                <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['paid']) }}</div><div class="stat-label">Paid</div></div>
                <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['pending']) }}</div><div class="stat-label">Pending</div></div>
                <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-calculator"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['average']) }}</div><div class="stat-label">Average</div></div>
                <div class="stat-card"><div class="stat-icon bg-pink-100 text-pink-600"><i class="fas fa-gift"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['total_bonus']) }}</div><div class="stat-label">Total Bonus</div></div>
            </div>

            {{-- Filters --}}
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 items-end">
                <div><label class="form-label">Month</label><select wire:model.live="monthFilter" class="form-select">
                    @foreach(range(1,12) as $m)
                        <option value="{{ $m }}">{{ date('F', mktime(0,0,0,$m)) }}</option>
                    @endforeach
                </select></div>
                <div><label class="form-label">Year</label><select wire:model.live="yearFilter" class="form-select">
                    @foreach(range(now()->year - 2, now()->year + 1) as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select></div>
                <div><label class="form-label">Search</label><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="search" wire:model.live.debounce.250ms="search" placeholder="Search staff..." class="form-input pl-10 focus:ring-0"></div></div>
                <div><label class="form-label">Role</label><select wire:model.live="roleFilter" class="form-select">
                    <option value="">All Roles</option>
                    <option value="super-admin">Super Admin</option>
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="editor">Editor</option>
                    <option value="videographer">Videographer</option>
                    <option value="designer">Designer</option>
                    <option value="copywriter">Copywriter</option>
                    <option value="social-media">Social Media</option>
                </select></div>
                <div><label class="form-label">Department</label><select wire:model.live="deptFilter" class="form-select">
                    <option value="">All Departments</option>
                    @foreach($this->departments as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select></div>
                <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="paid">Paid</option>
                    <option value="approved">Approved</option>
                </select></div>
            </div>

            {{-- Bulk Actions --}}
            @if($this->isMgr && count($this->selectedIds) > 0)
                <div class="flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-xl px-4 py-2.5">
                    <span class="text-sm font-medium text-blue-700"><i class="fas fa-check-double mr-1"></i> {{ count($this->selectedIds) }} selected</span>
                    <button wire:click="openBulkPayConfirm" class="btn btn-success btn-sm"><i class="fas fa-money-bill-wave text-xs"></i> Pay Selected</button>
                    <button wire:click="$set('selectedIds', []); $set('selectAll', false)" class="btn btn-ghost btn-sm text-gray-500"><i class="fas fa-times text-xs"></i> Clear</button>
                </div>
            @endif

            {{-- Table --}}
            <div class="overflow-x-auto">
                <table class="data-table w-full">
                    <thead><tr>
                        @if($this->isMgr)
                            <th class="w-10"><input type="checkbox" wire:click="toggleSelectAll" @if($selectAll) checked @endif class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5 cursor-pointer"></th>
                        @endif
                        <th>Staff</th><th>Role</th><th>Base</th><th>OT Pay</th><th>Bonus</th><th>Deduction</th><th>Leaves</th><th>Work Days</th><th>Net Salary</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                        @forelse($this->salaries as $s)
                            <tr>
                                @if($this->isMgr)
                                    <td class="w-10"><input type="checkbox" wire:click="toggleSelect({{ $s->id }})" @if(in_array($s->id, $this->selectedIds)) checked @endif class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5 cursor-pointer"></td>
                                @endif
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold">{{ strtoupper(substr($s->member_name, 0, 2)) }}</div>
                                        <div>
                                            <div class="font-medium text-sm">{{ $s->member_name }}</div>
                                            <div class="text-xs text-gray-400">{{ $s->dept_name ?? '' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge role-{{ $s->member_role }}">{{ roleName($s->member_role) }}</span></td>
                                <td>
                                    @if($this->isMgr)
                                        <button wire:click="openBaseEdit({{ $s->id }})" class="text-sm font-semibold hover:text-blue-500 hover:underline">{{ fmtCurrency($s->base_salary) }}</button>
                                    @else
                                        <span class="text-sm font-semibold">{{ fmtCurrency($s->base_salary) }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($this->isMgr && $s->overtime_pay > 0)
                                        <button wire:click="openOtDetail({{ $s->id }})" class="text-sm text-blue-600 hover:underline">{{ fmtCurrency($s->overtime_pay) }}</button>
                                    @else
                                        <span class="text-sm">{{ fmtCurrency($s->overtime_pay) }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($this->isMgr)
                                        <button wire:click="openBonusEdit({{ $s->id }})" class="text-sm hover:text-blue-500 hover:underline">{{ fmtCurrency($s->bonus) }}</button>
                                    @else
                                        <span class="text-sm">{{ fmtCurrency($s->bonus) }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($this->isMgr && $s->leave_deduction > 0)
                                        <button wire:click="openLeaveDetail({{ $s->id }})" class="text-sm text-red-600 hover:underline">{{ fmtCurrency($s->leave_deduction) }}</button>
                                    @else
                                        <span class="text-sm">{{ fmtCurrency($s->leave_deduction) }}</span>
                                    @endif
                                </td>
                                <td class="text-sm text-center">{{ $s->paid_leaves }}</td>
                                <td class="text-sm text-center">{{ $s->total_work_days }}</td>
                                <td class="font-bold text-green-600">{{ fmtCurrency($s->net_salary) }}</td>
                                <td>
                                    @if($this->isMgr)
                                        @if($s->status === 'pending')
                                            <button wire:click="openPayConfirm({{ $s->id }})" class="btn btn-success btn-xs"><i class="fas fa-money-bill-wave text-[10px]"></i> Pay</button>
                                        @else
                                            <span class="badge badge-{{ $s->status }}">{{ ucfirst($s->status) }}</span>
                                        @endif
                                    @else
                                        <span class="badge badge-{{ $s->status }}">{{ ucfirst($s->status) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-1">
                                        <button wire:click="openBreakdown({{ $s->id }})" class="btn btn-icon btn-ghost" title="View Breakdown"><i class="fas fa-eye text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                        @if($this->isMgr)
                                            <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Salary Record?', message: 'This salary record will be permanently removed.', type: 'danger', action: 'deleteSalary', params: [{{ $s->id }}] })" class="btn btn-icon btn-ghost" aria-label="Delete salary record" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="11">
                                <div class="py-16 text-center">
                                    <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"><i class="fas fa-money-bill-wave text-2xl text-gray-300"></i></div>
                                    <p class="text-gray-500 font-medium text-sm">No salary records found</p>
                                    <p class="text-gray-400 text-xs mt-1">Click "Ensure Records" to generate salary data</p>
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->getSalaries()->links() }}
            </div>

            {{-- Base Salary Edit Modal --}}
            @if($showBaseEdit && $this->selectedSalary)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showBaseEdit', false)" x-on:keydown.escape.window="$wire.set('showBaseEdit', false)">
                    <div class="modal-box w-full max-w-sm mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">Edit Base Salary</h3>
                            <button wire:click="$set('showBaseEdit', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="text-sm text-gray-500">{{ $this->selectedSalary->member_name }} — {{ date('F Y', mktime(0,0,0,$this->selectedSalary->month,1,$this->selectedSalary->year)) }}</div>
                            <div>
                                <label class="form-label">Base Salary</label>
                                <input type="number" wire:model="editBaseSalary" class="form-input" step="100" min="0">
                                <span wire:error="editBaseSalary" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-3 text-sm space-y-1">
                                <div class="flex justify-between"><span class="text-gray-500">New Base:</span><span class="font-semibold">{{ fmtCurrency($editBaseSalary) }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">OT Pay:</span><span>{{ fmtCurrency($this->selectedSalary->overtime_pay) }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">Bonus:</span><span>{{ fmtCurrency($this->selectedSalary->bonus) }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">Deduction:</span><span class="text-red-600">-{{ fmtCurrency($this->selectedSalary->leave_deduction) }}</span></div>
                                <hr>
                                <div class="flex justify-between font-bold"><span>Est. Net:</span><span class="text-green-600">{{ fmtCurrency($editBaseSalary + (float)$this->selectedSalary->overtime_pay + (float)$this->selectedSalary->bonus - (float)$this->selectedSalary->leave_deduction) }}</span></div>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showBaseEdit', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveBaseEdit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveBaseEdit"><span wire:loading.remove wire:target="saveBaseEdit">Save</span><span wire:loading wire:target="saveBaseEdit" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Bonus Edit Modal --}}
            @if($showBonusEdit && $this->selectedSalary)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showBonusEdit', false)" x-on:keydown.escape.window="$wire.set('showBonusEdit', false)">
                    <div class="modal-box w-full max-w-sm mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">Edit Bonus</h3>
                            <button wire:click="$set('showBonusEdit', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="text-sm text-gray-500">{{ $this->selectedSalary->member_name }}</div>
                            <div>
                                <label class="form-label">Bonus</label>
                                <input type="number" wire:model="editBonus" class="form-input" step="100" min="0">
                                <span wire:error="editBonus" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-3 text-sm space-y-1">
                                <div class="flex justify-between"><span class="text-gray-500">Base:</span><span>{{ fmtCurrency($this->selectedSalary->base_salary) }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">OT Pay:</span><span>{{ fmtCurrency($this->selectedSalary->overtime_pay) }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">New Bonus:</span><span class="font-semibold">{{ fmtCurrency($editBonus) }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">Deduction:</span><span class="text-red-600">-{{ fmtCurrency($this->selectedSalary->leave_deduction) }}</span></div>
                                <hr>
                                <div class="flex justify-between font-bold"><span>Est. Net:</span><span class="text-green-600">{{ fmtCurrency((float)$this->selectedSalary->base_salary + (float)$this->selectedSalary->overtime_pay + $editBonus - (float)$this->selectedSalary->leave_deduction) }}</span></div>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showBonusEdit', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveBonusEdit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveBonusEdit"><span wire:loading.remove wire:target="saveBonusEdit">Save</span><span wire:loading wire:target="saveBonusEdit" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- OT Detail Modal --}}
            @if($showOtDetail && $this->selectedSalary)
                <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/50 backdrop-blur-sm" wire:click.self="$set('showOtDetail', false)" x-on:keydown.escape.window="$wire.set('showOtDetail', false)">
                    <div class="bg-white w-full sm:w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl max-h-[85vh] sm:max-h-[80vh] flex flex-col shadow-2xl">
                        {{-- Header --}}
                        <div class="flex-shrink-0 flex items-center justify-between px-5 py-4 border-b">
                            <div class="min-w-0">
                                <h3 class="font-bold text-lg">Overtime Details</h3>
                                <p class="text-xs text-gray-400 mt-0.5 truncate">{{ $this->selectedSalary->member_name }} &middot; {{ date('M Y', mktime(0,0,0,$this->selectedSalary->month,1,$this->selectedSalary->year)) }}</p>
                            </div>
                            <button wire:click="$set('showOtDetail', false)" class="flex-shrink-0 ml-3 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times"></i></button>
                        </div>

                        {{-- Content --}}
                        <div class="flex-1 overflow-y-auto px-5 py-4">
                            @if($this->otLogs->count())
                                @php
                                    $unpaidOt = $this->otLogs->filter(fn($ot) => !$ot->paid);
                                    $paidOt = $this->otLogs->filter(fn($ot) => $ot->paid);
                                    $totalAmount = $this->otLogs->sum(fn($ot) => (float)$ot->hours * (float)$ot->rate);
                                    $unpaidAmount = $unpaidOt->sum(fn($ot) => (float)$ot->hours * (float)$ot->rate);
                                    $paidAmount = $paidOt->sum(fn($ot) => (float)$ot->hours * (float)$ot->rate);
                                @endphp

                                {{-- Summary Cards --}}
                                <div class="grid grid-cols-3 gap-2 mb-4">
                                    <div class="bg-green-50 border border-green-100 rounded-lg p-2.5 text-center">
                                        <div class="text-[10px] uppercase tracking-wider text-green-600/70 font-semibold">Total</div>
                                        <div class="font-bold text-green-700 text-sm mt-0.5">{{ fmtCurrency($totalAmount) }}</div>
                                    </div>
                                    <div class="bg-amber-50 border border-amber-100 rounded-lg p-2.5 text-center">
                                        <div class="text-[10px] uppercase tracking-wider text-amber-600/70 font-semibold">Unpaid</div>
                                        <div class="font-bold text-amber-700 text-sm mt-0.5">{{ fmtCurrency($unpaidAmount) }}</div>
                                    </div>
                                    <div class="bg-blue-50 border border-blue-100 rounded-lg p-2.5 text-center">
                                        <div class="text-[10px] uppercase tracking-wider text-blue-600/70 font-semibold">Paid</div>
                                        <div class="font-bold text-blue-700 text-sm mt-0.5">{{ fmtCurrency($paidAmount) }}</div>
                                    </div>
                                </div>

                                {{-- OT Entries --}}
                                <div class="space-y-2">
                                    @foreach($this->otLogs as $ot)
                                        @php $amount = (float)$ot->hours * (float)$ot->rate; @endphp
                                        <div class="flex items-center gap-3 p-3 rounded-xl border {{ $ot->paid ? 'bg-blue-50/50 border-blue-100' : 'bg-white border-gray-100' }}">
                                            {{-- Date Badge --}}
                                            <div class="w-11 h-11 rounded-lg {{ $ot->paid ? 'bg-blue-100' : 'bg-gray-100' }} flex flex-col items-center justify-center flex-shrink-0">
                                                <div class="text-[9px] font-semibold {{ $ot->paid ? 'text-blue-600' : 'text-gray-500' }} uppercase leading-none">{{ date('M', strtotime($ot->date)) }}</div>
                                                <div class="text-sm font-bold {{ $ot->paid ? 'text-blue-700' : 'text-gray-800' }} leading-none mt-0.5">{{ date('d', strtotime($ot->date)) }}</div>
                                            </div>

                                            {{-- Details --}}
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-baseline gap-1.5">
                                                    <span class="font-semibold text-sm text-gray-900">{{ $ot->hours }}h</span>
                                                    <span class="text-[10px] text-gray-400">x</span>
                                                    <span class="text-[11px] text-gray-500">{{ fmtCurrency($ot->rate) }}/hr</span>
                                                </div>
                                                @if($ot->description)
                                                    <p class="text-[11px] text-gray-400 mt-0.5 truncate">{{ $ot->description }}</p>
                                                @endif
                                            </div>

                                            {{-- Amount + Action --}}
                                            <div class="flex items-center gap-2 flex-shrink-0">
                                                <div class="text-right">
                                                    <div class="font-bold text-sm text-gray-900">{{ fmtCurrency($amount) }}</div>
                                                    <div class="text-[10px] font-semibold {{ $ot->paid ? 'text-blue-600' : 'text-amber-600' }}">{{ $ot->paid ? 'Paid' : 'Unpaid' }}</div>
                                                </div>
                                                @if(!$ot->paid)
                                                    <button wire:click="markOtPaid({{ $ot->id }})" class="w-8 h-8 rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center justify-center shadow-sm transition flex-shrink-0" title="Mark as Paid">
                                                        <i class="fas fa-check text-[11px]"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="py-10 text-center">
                                    <div class="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center mx-auto mb-2"><i class="fas fa-clock text-xl text-gray-300"></i></div>
                                    <p class="text-gray-400 text-sm">No approved overtime logs</p>
                                </div>
                            @endif
                        </div>

                        {{-- Footer --}}
                        @if($this->otLogs->count() && $unpaidOt->count())
                            <div class="flex-shrink-0 border-t px-5 py-3">
                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Pay All Unpaid Overtime?', message: 'This will mark all {{ $unpaidOt->count() }} unpaid overtime log(s) totaling {{ fmtCurrency($unpaidAmount) }} as paid. This action cannot be undone.', type: 'warning', action: 'markAllOtPaid', confirmLabel: 'Pay All' })" class="w-full bg-green-500 hover:bg-green-600 text-white font-semibold py-2.5 rounded-xl transition flex items-center justify-center gap-2">
                                    <i class="fas fa-money-bill-wave text-xs"></i> Pay All Unpaid ({{ fmtCurrency($unpaidAmount) }})
                                </button>
                            </div>
                        @elseif($this->otLogs->count())
                            <div class="flex-shrink-0 border-t px-5 py-3">
                                <div class="flex items-center justify-center gap-2 text-green-600 py-1">
                                    <i class="fas fa-check-circle text-sm"></i>
                                    <span class="text-sm font-semibold">All overtime paid</span>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Leave Deduction Detail Modal --}}
            @if($showLeaveDetail && $this->selectedSalary)
                <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/50 backdrop-blur-sm" wire:click.self="$set('showLeaveDetail', false)" x-on:keydown.escape.window="$wire.set('showLeaveDetail', false)">
                    <div class="bg-white w-full sm:w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl max-h-[85vh] sm:max-h-[80vh] flex flex-col shadow-2xl">
                        <div class="flex-shrink-0 flex items-center justify-between px-5 py-4 border-b">
                            <div class="min-w-0">
                                <h3 class="font-bold text-lg">Leave Details</h3>
                                <p class="text-xs text-gray-400 mt-0.5 truncate">{{ $this->selectedSalary->member_name }}</p>
                            </div>
                            <button wire:click="$set('showLeaveDetail', false)" class="flex-shrink-0 ml-3 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-3">
                            <div class="grid grid-cols-3 gap-2">
                                <div class="bg-blue-50 border border-blue-100 rounded-lg p-2.5 text-center"><div class="text-lg font-bold text-blue-600">{{ $this->selectedSalary->paid_leaves }}</div><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Paid</div></div>
                                <div class="bg-green-50 border border-green-100 rounded-lg p-2.5 text-center"><div class="text-lg font-bold text-green-600">{{ $this->selectedSalary->total_work_days }}</div><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Work Days</div></div>
                                <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5 text-center"><div class="text-lg font-bold">{{ fmtCurrency((float)$this->selectedSalary->base_salary / 30) }}</div><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Daily Rate</div></div>
                            </div>
                            @if($this->leaveLogs->count())
                                <div class="space-y-2">
                                    @foreach($this->leaveLogs as $lv)
                                        <div class="flex items-center gap-3 p-3 rounded-xl border border-gray-100">
                                            <div class="w-9 h-9 rounded-lg {{ in_array($lv->type, ['annual','casual']) ? 'bg-green-100' : 'bg-red-100' }} flex items-center justify-center flex-shrink-0">
                                                <i class="fas {{ in_array($lv->type, ['annual','casual']) ? 'fa-umbrella-beach text-green-600' : 'fa-calendar-minus text-red-500' }} text-xs"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-semibold text-sm capitalize">{{ $lv->type }}</span>
                                                    <span class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full {{ in_array($lv->type, ['annual','casual']) ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600' }}">{{ in_array($lv->type, ['annual','casual']) ? 'Paid' : 'Unpaid' }}</span>
                                                </div>
                                                <p class="text-[11px] text-gray-400 mt-0.5">{{ fmtDate($lv->start_date) }} — {{ fmtDate($lv->end_date) }}</p>
                                            </div>
                                            <div class="text-right flex-shrink-0">
                                                <div class="font-bold text-sm">{{ $days = (int)\Carbon\Carbon::parse($lv->start_date)->diffInDays(\Carbon\Carbon::parse($lv->end_date)) + 1 }}d</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="py-8 text-center">
                                    <div class="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center mx-auto mb-2"><i class="fas fa-umbrella-beach text-lg text-gray-300"></i></div>
                                    <p class="text-gray-400 text-sm">No approved leaves</p>
                                </div>
                            @endif
                        </div>
                        <div class="flex-shrink-0 border-t px-5 py-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-500">Total Deduction</span>
                                <span class="font-bold text-red-600 text-lg">{{ fmtCurrency($this->selectedSalary->leave_deduction) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Breakdown Modal --}}
            @if($showBreakdown && $this->selectedSalary)
                <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/50 backdrop-blur-sm" wire:click.self="$set('showBreakdown', false)" x-on:keydown.escape.window="$wire.set('showBreakdown', false)">
                    <div class="bg-white w-full sm:w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl max-h-[85vh] sm:max-h-[80vh] flex flex-col shadow-2xl">
                        <div class="flex-shrink-0 flex items-center justify-between px-5 py-4 border-b">
                            <h3 class="font-bold text-lg">Salary Breakdown</h3>
                            <button wire:click="$set('showBreakdown', false)" class="flex-shrink-0 ml-3 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-3">
                            <div class="text-center">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center mx-auto mb-1.5 font-bold text-sm text-gray-600">{{ strtoupper(substr($this->selectedSalary->member_name, 0, 2)) }}</div>
                                <div class="font-bold text-sm">{{ $this->selectedSalary->member_name }}</div>
                                <div class="text-[11px] text-gray-400">{{ date('F Y', mktime(0,0,0,$this->selectedSalary->month,1,$this->selectedSalary->year)) }}</div>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="bg-blue-50 border border-blue-100 rounded-lg p-2.5"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Base Salary</div><div class="font-bold text-sm mt-0.5">{{ fmtCurrency($this->selectedSalary->base_salary) }}</div></div>
                                <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Daily Rate</div><div class="font-bold text-sm mt-0.5">{{ fmtCurrency((float)$this->selectedSalary->base_salary / 30) }}</div></div>
                                <div class="bg-green-50 border border-green-100 rounded-lg p-2.5">
                                    <div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Overtime Pay</div>
                                    <div class="font-bold text-sm mt-0.5">{{ fmtCurrency($this->selectedSalary->overtime_pay) }}</div>
                                    @php
                                        $unpaidOt = $this->otLogs->filter(fn($ot) => !$ot->paid);
                                        $unpaidOtAmount = $unpaidOt->sum(fn($ot) => (float)$ot->hours * (float)$ot->rate);
                                    @endphp
                                    @if($unpaidOtAmount > 0)
                                        <div class="text-[10px] text-amber-600 font-semibold mt-0.5"><i class="fas fa-exclamation-circle"></i> {{ fmtCurrency($unpaidOtAmount) }} unpaid</div>
                                    @endif
                                </div>
                                <div class="bg-purple-50 border border-purple-100 rounded-lg p-2.5"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Bonus</div><div class="font-bold text-sm mt-0.5">{{ fmtCurrency($this->selectedSalary->bonus) }}</div></div>
                                <div class="bg-red-50 border border-red-100 rounded-lg p-2.5"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Leave Deduction</div><div class="font-bold text-sm mt-0.5 text-red-600">-{{ fmtCurrency($this->selectedSalary->leave_deduction) }}</div></div>
                                <div class="bg-amber-50 border border-amber-100 rounded-lg p-2.5"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Paid Leaves</div><div class="font-bold text-sm mt-0.5">{{ $this->selectedSalary->paid_leaves }}d</div></div>
                                <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Work Days</div><div class="font-bold text-sm mt-0.5">{{ $this->selectedSalary->total_work_days }}d</div></div>
                                <div class="bg-cyan-50 border border-cyan-100 rounded-lg p-2.5"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Unpaid Leaves</div><div class="font-bold text-sm mt-0.5">{{ $this->selectedSalary->unpaid_leaves }}d</div></div>
                            </div>
                            <div class="bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl p-4 text-white text-center">
                                <div class="text-[10px] uppercase tracking-wider opacity-80 font-semibold">Net Salary</div>
                                <div class="text-2xl font-extrabold mt-1">{{ fmtCurrency($this->selectedSalary->net_salary) }}</div>
                                <div class="text-[10px] opacity-60 mt-1.5">{{ fmtCurrency($this->selectedSalary->base_salary) }} + {{ fmtCurrency($this->selectedSalary->overtime_pay) }} + {{ fmtCurrency($this->selectedSalary->bonus) }} - {{ fmtCurrency($this->selectedSalary->leave_deduction) }}</div>
                            </div>
                        </div>
                        <div class="flex-shrink-0 border-t px-5 py-3">
                            <button wire:click="printSlip({{ $this->selectedSalary->id }})" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2.5 rounded-xl transition flex items-center justify-center gap-2">
                                <i class="fas fa-print text-sm"></i> Print Salary Slip
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Single Pay Confirmation Modal --}}
            @if($showPayConfirm && $this->paySalaryDetail)
                @php $ps = $this->paySalaryDetail; @endphp
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showPayConfirm', false)" x-on:keydown.escape.window="$wire.set('showPayConfirm', false)">
                    <div class="modal-box w-full max-w-sm mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg text-green-600"><i class="fas fa-money-bill-wave mr-2"></i>Confirm Payment</h3>
                            <button wire:click="$set('showPayConfirm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="text-center">
                                <div class="w-14 h-14 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-2">
                                    <div class="font-bold text-lg text-green-700">{{ strtoupper(substr($ps->member_name, 0, 2)) }}</div>
                                </div>
                                <div class="font-bold text-gray-900">{{ $ps->member_name }}</div>
                                <div class="text-xs text-gray-500">{{ date('F Y', mktime(0,0,0,$ps->month,1,$ps->year)) }}</div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-3 space-y-2">
                                <div class="flex justify-between text-sm"><span class="text-gray-500">Base Salary</span><span>{{ fmtCurrency($ps->base_salary) }}</span></div>
                                <div class="flex justify-between text-sm"><span class="text-gray-500">Overtime Pay</span><span class="text-green-600">+{{ fmtCurrency($ps->overtime_pay) }}</span></div>
                                <div class="flex justify-between text-sm"><span class="text-gray-500">Bonus</span><span class="text-green-600">+{{ fmtCurrency($ps->bonus) }}</span></div>
                                @if($ps->leave_deduction > 0)
                                <div class="flex justify-between text-sm"><span class="text-gray-500">Leave Deduction</span><span class="text-red-600">-{{ fmtCurrency($ps->leave_deduction) }}</span></div>
                                @endif
                                <div class="border-t border-gray-200 pt-2 flex justify-between font-bold"><span>Net Salary</span><span class="text-green-600 text-lg">{{ fmtCurrency($ps->net_salary) }}</span></div>
                            </div>
                            <div class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-700">
                                <i class="fas fa-info-circle mr-1"></i> This will mark the salary as <strong>paid</strong>. This action cannot be undone.
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showPayConfirm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="confirmPay" class="btn btn-success" wire:loading.attr="disabled" wire:target="confirmPay"><i class="fas fa-check text-xs"></i> Confirm Payment</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Bulk Pay Confirmation Modal --}}
            @if($showBulkPayConfirm)
                @php $bulk = $this->bulkPayDetails; @endphp
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showBulkPayConfirm', false)" x-on:keydown.escape.window="$wire.set('showBulkPayConfirm', false)">
                    <div class="modal-box w-full max-w-md mx-4 max-h-[80vh] overflow-y-auto">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b z-10">
                            <h3 class="font-bold text-lg text-green-600"><i class="fas fa-money-bill-wave mr-2"></i>Confirm Bulk Payment</h3>
                            <button wire:click="$set('showBulkPayConfirm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-center">
                                <div class="text-sm text-green-700">Paying <span class="font-bold">{{ $bulk['count'] }}</span> staff member(s)</div>
                                <div class="text-2xl font-extrabold text-green-600 mt-1">{{ fmtCurrency($bulk['total']) }}</div>
                                <div class="text-xs text-green-500 mt-1">Total amount to be paid</div>
                            </div>

                            @if($bulk['count'] > 0)
                            <div class="space-y-1.5">
                                @foreach($bulk['items'] as $item)
                                    <div class="flex items-center justify-between bg-white border border-gray-100 rounded-lg px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-full bg-gray-200 flex items-center justify-center text-[10px] font-bold">{{ strtoupper(substr($item->member_name, 0, 2)) }}</div>
                                            <span class="text-sm font-medium">{{ $item->member_name }}</span>
                                        </div>
                                        <span class="text-sm font-bold text-green-600">{{ fmtCurrency($item->net_salary) }}</span>
                                    </div>
                                @endforeach
                            </div>
                            @else
                                <p class="text-sm text-gray-400 text-center py-4">No pending salaries found in selection</p>
                            @endif

                            <div class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-700">
                                <i class="fas fa-info-circle mr-1"></i> This will mark all pending salaries in your selection as <strong>paid</strong>.
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showBulkPayConfirm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="confirmBulkPay" class="btn btn-success" wire:loading.attr="disabled" wire:target="confirmBulkPay"><i class="fas fa-check-double text-xs"></i> Pay All ({{ $bulk['count'] }})</button>
                        </div>
                    </div>
                </div>
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
