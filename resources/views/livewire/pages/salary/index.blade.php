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
    public int $selectedSalaryId = 0;
    public float $editBaseSalary = 0;
    public float $editBonus = 0;
    public float $editDeduction = 0;

    protected SalaryCalculator $calculator;

    public function boot(): void
    {
        $this->calculator = app(SalaryCalculator::class);
    }

    public function mount(): void
    {
        $this->monthFilter = (int) now()->month;
        $this->yearFilter = (int) now()->year;
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
            $this->dispatch('toast', message: "Status changed to $next", type: 'success');
        }
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
        $created = 0;
        foreach ($activeUsers as $u) {
            $model = \App\Models\User::find($u->id);
            if ($model) {
                $this->calculator->ensureFor($model, $this->monthFilter, $this->yearFilter);
                $created++;
            }
        }
        $this->dispatch('toast', message: "Salary records ensured for $created staff", type: 'success');
    }

    public function getOtLogs()
    {
        $salary = $this->getSelectedSalary();
        if (!$salary) return collect();
        return DB::table('overtime_logs')
            ->join('users', 'overtime_logs.member_id', '=', 'users.id')
            ->where('overtime_logs.member_id', $salary->member_id)
            ->whereRaw('MONTH(overtime_logs.date) = ?', [$salary->month])
            ->whereRaw('YEAR(overtime_logs.date) = ?', [$salary->year])
            ->where('overtime_logs.approved', true)
            ->select('overtime_logs.*', 'users.name as member_name')
            ->orderBy('overtime_logs.date')
            ->get();
    }

    public function getLeaveLogs()
    {
        $salary = $this->getSelectedSalary();
        if (!$salary) return collect();
        return DB::table('leaves')
            ->join('users', 'leaves.member_id', '=', 'users.id')
            ->where('leaves.member_id', $salary->member_id)
            ->where('leaves.status', 'approved')
            ->whereRaw('MONTH(leaves.start_date) <= ?', [$salary->month])
            ->whereRaw('MONTH(leaves.end_date) >= ?', [$salary->month])
            ->whereRaw('YEAR(leaves.start_date) <= ?', [$salary->year])
            ->whereRaw('YEAR(leaves.end_date) >= ?', [$salary->year])
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

            {{-- Table --}}
            <div class="overflow-x-auto">
                <table class="data-table w-full">
                    <thead><tr><th>Staff</th><th>Role</th><th>Base</th><th>OT Pay</th><th>Bonus</th><th>Deduction</th><th>Leaves</th><th>Work Days</th><th>Net Salary</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse($this->salaries as $s)
                            <tr>
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
                                        <button wire:click="cycleStatus({{ $s->id }})" class="badge badge-{{ $s->status }} cursor-pointer hover:opacity-80">
                                            {{ ucfirst($s->status) }}
                                        </button>
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
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showOtDetail', false)" x-on:keydown.escape.window="$wire.set('showOtDetail', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">Overtime Details</h3>
                            <button wire:click="$set('showOtDetail', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4">
                            <div class="text-sm text-gray-500 mb-3">{{ $this->selectedSalary->member_name }}</div>
                            @if($this->otLogs->count())
                                <table class="data-table w-full text-sm">
                                    <thead><tr><th>Date</th><th>Hours</th><th>Rate</th><th>Amount</th></tr></thead>
                                    <tbody>
                                        @foreach($this->otLogs as $ot)
                                            <tr><td>{{ fmtDate($ot->date) }}</td><td>{{ $ot->hours }}h</td><td>{{ fmtCurrency($ot->rate) }}/hr</td><td class="font-semibold">{{ fmtCurrency((float)$ot->hours * (float)$ot->rate) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <div class="text-right font-bold mt-3 text-green-600">Total: {{ fmtCurrency($this->selectedSalary->overtime_pay) }}</div>
                            @else
                                <p class="text-center text-gray-400 py-4">No approved overtime logs</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Leave Deduction Detail Modal --}}
            @if($showLeaveDetail && $this->selectedSalary)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showLeaveDetail', false)" x-on:keydown.escape.window="$wire.set('showLeaveDetail', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">Leave Deduction Details</h3>
                            <button wire:click="$set('showLeaveDetail', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="text-sm text-gray-500">{{ $this->selectedSalary->member_name }}</div>
                            <div class="grid grid-cols-3 gap-3">
                                <div class="bg-blue-50 rounded-xl p-3 text-center"><div class="text-lg font-bold text-blue-600">{{ $this->selectedSalary->paid_leaves }}</div><div class="text-xs text-gray-500">Paid Leaves</div></div>
                                <div class="bg-green-50 rounded-xl p-3 text-center"><div class="text-lg font-bold text-green-600">{{ $this->selectedSalary->total_work_days }}</div><div class="text-xs text-gray-500">Work Days</div></div>
                                <div class="bg-gray-50 rounded-xl p-3 text-center"><div class="text-lg font-bold">{{ fmtCurrency((float)$this->selectedSalary->base_salary / 30) }}</div><div class="text-xs text-gray-500">Daily Rate</div></div>
                            </div>
                            @if($this->leaveLogs->count())
                                <table class="data-table w-full text-sm">
                                    <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Category</th></tr></thead>
                                    <tbody>
                                        @foreach($this->leaveLogs as $lv)
                                            <tr>
                                                <td class="capitalize">{{ $lv->type }}</td>
                                                <td>{{ fmtDate($lv->start_date) }} — {{ fmtDate($lv->end_date) }}</td>
                                                <td>{{ $days = (int)\Carbon\Carbon::parse($lv->start_date)->diffInDays(\Carbon\Carbon::parse($lv->end_date)) + 1 }}</td>
                                                <td><span class="badge {{ in_array($lv->type, ['annual','casual']) ? 'badge-approved' : 'badge-pending' }}">{{ in_array($lv->type, ['annual','casual']) ? 'Paid' : 'Unpaid' }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @else
                                <p class="text-center text-gray-400 py-2">No approved leaves</p>
                            @endif
                            <div class="text-right font-bold text-red-600">Total Deduction: {{ fmtCurrency($this->selectedSalary->leave_deduction) }}</div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Breakdown Modal --}}
            @if($showBreakdown && $this->selectedSalary)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showBreakdown', false)" x-on:keydown.escape.window="$wire.set('showBreakdown', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">Salary Breakdown</h3>
                            <button wire:click="$set('showBreakdown', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="text-center">
                                <div class="w-14 h-14 rounded-full bg-gray-200 flex items-center justify-center mx-auto mb-2 font-bold text-lg">{{ strtoupper(substr($this->selectedSalary->member_name, 0, 2)) }}</div>
                                <div class="font-bold">{{ $this->selectedSalary->member_name }}</div>
                                <div class="text-sm text-gray-500">{{ date('F Y', mktime(0,0,0,$this->selectedSalary->month,1,$this->selectedSalary->year)) }}</div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="bg-blue-50 rounded-xl p-3"><div class="text-xs text-gray-500">Base Salary</div><div class="font-bold">{{ fmtCurrency($this->selectedSalary->base_salary) }}</div></div>
                                <div class="bg-gray-50 rounded-xl p-3"><div class="text-xs text-gray-500">Daily Rate</div><div class="font-bold">{{ fmtCurrency((float)$this->selectedSalary->base_salary / 30) }}</div></div>
                                <div class="bg-green-50 rounded-xl p-3"><div class="text-xs text-gray-500">Overtime Pay</div><div class="font-bold">{{ fmtCurrency($this->selectedSalary->overtime_pay) }}</div></div>
                                <div class="bg-purple-50 rounded-xl p-3"><div class="text-xs text-gray-500">Bonus</div><div class="font-bold">{{ fmtCurrency($this->selectedSalary->bonus) }}</div></div>
                                <div class="bg-red-50 rounded-xl p-3"><div class="text-xs text-gray-500">Leave Deduction</div><div class="font-bold text-red-600">-{{ fmtCurrency($this->selectedSalary->leave_deduction) }}</div></div>
                                <div class="bg-amber-50 rounded-xl p-3"><div class="text-xs text-gray-500">Paid Leaves</div><div class="font-bold">{{ $this->selectedSalary->paid_leaves }} days</div></div>
                                <div class="bg-gray-50 rounded-xl p-3"><div class="text-xs text-gray-500">Work Days</div><div class="font-bold">{{ $this->selectedSalary->total_work_days }} days</div></div>
                                <div class="bg-cyan-50 rounded-xl p-3"><div class="text-xs text-gray-500">Unpaid Leaves</div><div class="font-bold">{{ $this->selectedSalary->unpaid_leaves }} days</div></div>
                            </div>
                            <div class="bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl p-4 text-white text-center">
                                <div class="text-sm opacity-80">Net Salary</div>
                                <div class="text-2xl font-extrabold">{{ fmtCurrency($this->selectedSalary->net_salary) }}</div>
                                <div class="text-xs opacity-60 mt-1">{{ fmtCurrency($this->selectedSalary->base_salary) }} + {{ fmtCurrency($this->selectedSalary->overtime_pay) }} + {{ fmtCurrency($this->selectedSalary->bonus) }} - {{ fmtCurrency($this->selectedSalary->leave_deduction) }}</div>
                            </div>
                            <button wire:click="printSlip({{ $this->selectedSalary->id }})" class="btn btn-secondary w-full"><i class="fas fa-print text-sm"></i> Print Salary Slip</button>
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
