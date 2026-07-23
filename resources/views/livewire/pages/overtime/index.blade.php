<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $monthFilter = '';
    public string $statusFilter = '';
    public int $staffFilter = 0;
    public bool $showForm = false;
    public int $editingId = 0;
    public int $formMemberId = 0;
    public string $formDate = '';
    public float $formHours = 1.0;
    public float $formRate = 0;
    public string $formDescription = '';

    public function mount(): void
    {
        $this->formDate = now()->format('Y-m-d');
        $this->formRate = (float) (DB::table('settings')->value('overtime_rate_default') ?? 500);
    }

    public function isManager(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        return in_array($user->role, ['super-admin', 'admin', 'manager']);
    }

    public function getStats(): array
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $userId = Auth::id();
        $isMgr = $this->isManager();

        $base = DB::table('overtime_logs');
        if (!$isMgr) $base->where('member_id', $userId);

        $monthQ = (clone $base)->whereBetween('date', [$startOfMonth, $endOfMonth]);

        $totalHours = (clone $monthQ)->sum('hours');
        $pending = (clone $monthQ)->where('approved', false)->count();
        $totalCost = (clone $monthQ)->where('approved', true)
            ->selectRaw('COALESCE(SUM(hours * rate), 0)')
            ->value('hours') ?? 0;
        // Recalculate totalCost properly
        $totalCost = (clone $monthQ)->where('approved', true)
            ->get()
            ->sum(fn($o) => (float) $o->hours * (float) $o->rate);
        $staffCount = (clone $monthQ)->where('approved', true)->distinct('member_id')->count('member_id');

        return [
            'month_hours' => round($totalHours, 1),
            'pending' => $pending,
            'total_cost' => $totalCost,
            'staff_count' => $staffCount,
        ];
    }

    public function getLogs()
    {
        $q = DB::table('overtime_logs')
            ->join('users', 'overtime_logs.member_id', '=', 'users.id');

        if (!$this->isManager()) {
            $q->where('overtime_logs.member_id', Auth::id());
        } elseif ($this->staffFilter) {
            $q->where('overtime_logs.member_id', $this->staffFilter);
        }

        if ($this->monthFilter) {
            $q->whereRaw('YEAR(overtime_logs.date) = ?', [substr($this->monthFilter, 0, 4)])
              ->whereRaw('MONTH(overtime_logs.date) = ?', [substr($this->monthFilter, 5, 2)]);
        }

        if ($this->statusFilter === 'approved') {
            $q->where('overtime_logs.approved', true);
        } elseif ($this->statusFilter === 'pending') {
            $q->where('overtime_logs.approved', false);
        } elseif ($this->statusFilter === 'paid') {
            $q->where('overtime_logs.paid', true);
        }

        if ($this->search) {
            $q->where(function ($sub) {
                $sub->where('users.name', 'like', "%{$this->search}%")
                    ->orWhere('overtime_logs.description', 'like', "%{$this->search}%");
            });
        }

        return $q->select('overtime_logs.*', 'users.name as member_name')
            ->orderBy('overtime_logs.date', 'desc')
            ->paginate(50);
    }

    public function getStaffSummary()
    {
        if (!$this->isManager()) return collect();

        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $monthFilter = $this->monthFilter;
        if ($monthFilter) {
            $startOfMonth = \Illuminate\Support\Carbon::createFromDate(substr($monthFilter, 0, 4), substr($monthFilter, 5, 2), 1)->startOfMonth();
            $endOfMonth = $startOfMonth->copy()->endOfMonth();
        }

        return DB::table('overtime_logs')
            ->join('users', 'overtime_logs.member_id', '=', 'users.id')
            ->whereBetween('overtime_logs.date', [$startOfMonth, $endOfMonth])
            ->where('overtime_logs.approved', true)
            ->select('overtime_logs.member_id', 'users.name as member_name')
            ->selectRaw('SUM(overtime_logs.hours) as total_hours')
            ->selectRaw('SUM(overtime_logs.hours * overtime_logs.rate) as total_amount')
            ->groupBy('overtime_logs.member_id', 'users.name')
            ->orderBy('total_amount', 'desc')
            ->get();
    }

    public function openForm(?int $id = null): void
    {
        if ($id) {
            $log = DB::table('overtime_logs')->where('id', $id)->first();
            if ($log) {
                $this->editingId = $id;
                $this->formMemberId = $log->member_id;
                $this->formDate = $log->date;
                $this->formHours = (float) $log->hours;
                $this->formRate = (float) $log->rate;
                $this->formDescription = $log->description ?? '';
            }
        } else {
            $this->editingId = 0;
            $this->formMemberId = $this->isManager() ? 0 : Auth::id();
            $this->formDate = now()->format('Y-m-d');
            $this->formHours = 1.0;
            $this->formRate = (float) (DB::table('settings')->value('overtime_rate_default') ?? 500);
            $this->formDescription = '';
        }
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'formDate' => 'required|date',
            'formHours' => 'required|numeric|min:0.5|max:24',
            'formRate' => 'required|numeric|min:0',
        ]);

        $memberId = $this->isManager() ? ($this->formMemberId ?: Auth::id()) : Auth::id();

        $data = [
            'member_id' => $memberId,
            'date' => $this->formDate,
            'hours' => $this->formHours,
            'rate' => $this->formRate,
            'description' => $this->formDescription ?: null,
        ];

        if ($this->editingId) {
            DB::table('overtime_logs')->where('id', $this->editingId)->update($data + ['updated_at' => now()]);
            // Recalculate salary for that member/month/year
            $date = \Carbon\Carbon::parse($this->formDate);
            $salary = \App\Models\Salary::where('member_id', $memberId)
                ->where('month', $date->month)
                ->where('year', $date->year)
                ->first();
            if ($salary) {
                app(\App\Services\SalaryCalculator::class)->recalc($salary);
            }
        } else {
            $data['approved'] = $this->isManager();
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('overtime_logs')->insert($data);
            // If auto-approved, recalculate salary
            if ($this->isManager()) {
                $date = \Carbon\Carbon::parse($this->formDate);
                $salary = \App\Models\Salary::where('member_id', $memberId)
                    ->where('month', $date->month)
                    ->where('year', $date->year)
                    ->first();
                if ($salary) {
                    app(\App\Services\SalaryCalculator::class)->recalc($salary);
                }
            }
        }

        $this->showForm = false;
        $this->dispatch('toast', message: 'Overtime log saved', type: 'success');
    }

    public function toggleApprove(int $id): void
    {
        if (!$this->isManager()) return;
        $log = DB::table('overtime_logs')->where('id', $id)->first();
        if ($log) {
            $newApproved = !$log->approved;
            DB::table('overtime_logs')->where('id', $id)->update([
                'approved' => $newApproved,
                'paid' => $newApproved ? $log->paid : false,
                'updated_at' => now(),
            ]);

            // Recalculate salary for that member/month/year
            $date = \Carbon\Carbon::parse($log->date);
            $salary = \App\Models\Salary::where('member_id', $log->member_id)
                ->where('month', $date->month)
                ->where('year', $date->year)
                ->first();
            if ($salary) {
                app(\App\Services\SalaryCalculator::class)->recalc($salary);
            }

            $this->dispatch('toast', message: $log->approved ? 'Approval revoked' : 'Overtime approved', type: 'success');
        }
    }

    public function deleteLog(int $id): void
    {
        $log = DB::table('overtime_logs')->where('id', $id)->first();
        if ($log) {
            DB::table('overtime_logs')->where('id', $id)->delete();
            // Recalculate salary for that member/month/year
            $date = \Carbon\Carbon::parse($log->date);
            $salary = \App\Models\Salary::where('member_id', $log->member_id)
                ->where('month', $date->month)
                ->where('year', $date->year)
                ->first();
            if ($salary) {
                app(\App\Services\SalaryCalculator::class)->recalc($salary);
            }
            $this->dispatch('toast', message: 'Overtime log deleted', type: 'success');
        }
    }

    public function togglePaid(int $id): void
    {
        if (!$this->isManager()) return;
        $log = DB::table('overtime_logs')->where('id', $id)->first();
        if ($log) {
            DB::table('overtime_logs')->where('id', $id)->update([
                'paid' => !$log->paid,
                'updated_at' => now(),
            ]);
            $this->dispatch('toast', message: $log->paid ? 'Marked as unpaid' : 'Marked as paid', type: 'success');
        }
    }

    public function markAllPaid(): void
    {
        if (!$this->isManager()) return;
        $month = $this->monthFilter;
        $q = DB::table('overtime_logs')->where('approved', true)->where('paid', false);
        if ($month) {
            $q->whereRaw('YEAR(date) = ?', [substr($month, 0, 4)])
              ->whereRaw('MONTH(date) = ?', [substr($month, 5, 2)]);
        }
        $count = $q->update(['paid' => true, 'updated_at' => now()]);
        $this->dispatch('toast', message: "$count overtime log(s) marked as paid", type: 'success');
    }

    #[Computed]
    public function stats(): array { return $this->getStats(); }

    #[Computed]
    public function logs() { return $this->getLogs(); }

    #[Computed]
    public function staffSummary() { return $this->getStaffSummary(); }

    #[Computed]
    public function isMgr(): bool { return $this->isManager(); }

    #[Computed]
    public function team()
    {
        return DB::table('users')->where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function months(): array
    {
        return DB::table('overtime_logs')
            ->selectRaw('DISTINCT DATE_FORMAT(date, "%Y-%m") as month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->pluck('month')
            ->toArray();
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Overtime</h1>
                    <p class="text-sm text-gray-500 mt-1">Track and manage overtime logs</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ url('overtime/export') }}" class="btn btn-secondary btn-sm"><i class="fas fa-download text-sm"></i> CSV</a>
                    <button wire:click="openForm" class="btn btn-primary"><i class="fas fa-plus text-sm"></i> Log Overtime</button>
                </div>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ $this->stats['month_hours'] }}h</div><div class="stat-label">This Month Hours</div></div>
                <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-hourglass-half"></i></div><div class="stat-value">{{ $this->stats['pending'] }}</div><div class="stat-label">Pending Approvals</div></div>
                <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-dollar-sign"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['total_cost']) }}</div><div class="stat-label">Total Cost</div></div>
                <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-users"></i></div><div class="stat-value">{{ $this->stats['staff_count'] }}</div><div class="stat-label">Staff with OT</div></div>
            </div>

            {{-- Summary by Staff (Managers) --}}
            @if($this->isMgr && $this->staffSummary->count())
                <div>
                    <h2 class="font-bold text-lg mb-3">Summary by Staff</h2>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        @foreach($this->staffSummary as $s)
                            <div class="bg-white border rounded-xl p-4 flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center font-bold text-sm">{{ strtoupper(substr($s->member_name, 0, 2)) }}</div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold text-sm truncate">{{ $s->member_name }}</div>
                                    <div class="text-xs text-gray-500">{{ round($s->total_hours, 1) }} hours</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-sm text-green-600">{{ fmtCurrency($s->total_amount) }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Filters --}}
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 items-end">
                <div><label class="form-label">Search</label><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="search" wire:model.live.debounce.250ms="search" placeholder="Search staff or description..." class="form-input pl-10 focus:ring-0"></div></div>
                <div><label class="form-label">Month</label><select wire:model.live="monthFilter" class="form-select">
                    <option value="">All Months</option>
                    @foreach($this->months as $m)
                        <option value="{{ $m }}">{{ $m }}</option>
                    @endforeach
                </select></div>
                <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select">
                    <option value="">All Status</option>
                    <option value="approved">Approved</option>
                    <option value="pending">Pending</option>
                    <option value="paid">Paid</option>
                </select></div>
                @if($this->isMgr)
                    <div><label class="form-label">Staff</label><select wire:model.live="staffFilter" class="form-select">
                        <option value="">All Staff</option>
                        @foreach($this->team as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select></div>
                @endif
            </div>

            {{-- Bulk Pay Action --}}
            @if($this->isMgr)
                <div class="flex items-center gap-3">
                    <button type="button" wire:click="$dispatch('open-confirm', { title: 'Mark All as Paid?', message: 'This will mark all approved overtime logs as paid. This action cannot be undone.', type: 'warning', action: 'markAllPaid', confirmLabel: 'Mark All Paid' })" class="btn btn-success btn-sm"><i class="fas fa-money-bill-wave text-xs"></i> Mark All Approved as Paid</button>
                </div>
            @endif

            {{-- Table --}}
            <div class="overflow-x-auto">
                <table class="data-table w-full">
                    <thead><tr><th>Staff</th><th>Date</th><th>Hours</th><th>Rate</th><th>Amount</th><th>Description</th><th>Approved</th><th>Paid</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse($this->logs as $log)
                            <tr>
                                <td class="font-medium">{{ $log->member_name }}</td>
                                <td class="text-sm">{{ fmtDate($log->date) }}</td>
                                <td>{{ $log->hours }}h</td>
                                <td class="text-sm">{{ fmtCurrency($log->rate) }}/hr</td>
                                <td class="font-semibold text-green-600">{{ fmtCurrency((float) $log->hours * (float) $log->rate) }}</td>
                                <td class="text-sm text-gray-600 max-w-[200px] truncate">{{ $log->description ?? '-' }}</td>
                                <td>
                                    @if($this->isMgr)
                                        <button wire:click="toggleApprove({{ $log->id }})" class="toggle-switch {{ $log->approved ? 'on' : '' }}"></button>
                                    @else
                                        <span class="badge {{ $log->approved ? 'badge-approved' : 'badge-pending' }}">{{ $log->approved ? 'Yes' : 'No' }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($log->paid)
                                        <span class="badge badge-approved"><i class="fas fa-check-circle text-[10px] mr-1"></i>Paid</span>
                                    @elseif($this->isMgr && $log->approved)
                                        <button wire:click="togglePaid({{ $log->id }})" class="btn btn-success btn-xs"><i class="fas fa-money-bill-wave text-[10px]"></i> Pay</button>
                                    @else
                                        <span class="badge badge-pending">Unpaid</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-1">
                                        <button wire:click="openForm({{ $log->id }})" class="btn btn-icon btn-ghost" title="Edit"><i class="fas fa-pen text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                        <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Overtime Log?', message: 'This overtime log will be permanently removed.', type: 'danger', action: 'deleteLog', params: [{{ $log->id }}] })" class="btn btn-icon btn-ghost" aria-label="Delete overtime log" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9">
                                <div class="py-16 text-center">
                                    <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"><i class="fas fa-clock text-2xl text-gray-300"></i></div>
                                    <p class="text-gray-500 font-medium text-sm">No overtime logs found</p>
                                    <p class="text-gray-400 text-xs mt-1">Log overtime hours to get started</p>
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->logs->links() }}
            </div>

            {{-- Overtime Form Modal --}}
            @if($showForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showForm', false)" x-on:keydown.escape.window="$wire.set('showForm', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingId ? 'Edit' : 'Log' }} Overtime</h3>
                            <button wire:click="$set('showForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            @if($this->isMgr)
                                <div>
                                    <label class="form-label">Staff Member</label>
                                    <select wire:model="formMemberId" class="form-select">
                                        <option value="">Select staff</option>
                                        @foreach($this->team as $t)
                                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <div>
                                <label class="form-label">Date</label>
                                <input type="date" wire:model="formDate" class="form-input">
                                <span wire:error="formDate" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Hours (0.5 step)</label>
                                    <input type="number" wire:model="formHours" class="form-input" step="0.5" min="0.5" max="24">
                                    <span wire:error="formHours" class="text-red-500 text-xs mt-1 block"></span>
                                </div>
                                <div>
                                    <label class="form-label">Rate per Hour</label>
                                    <input type="number" wire:model="formRate" class="form-input" step="0.01" min="0">
                                    <span wire:error="formRate" class="text-red-500 text-xs mt-1 block"></span>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Amount</label>
                                <div class="text-lg font-bold text-green-600">{{ fmtCurrency($this->formHours * $this->formRate) }}</div>
                            </div>
                            <div>
                                <label class="form-label">Description</label>
                                <textarea wire:model="formDescription" class="form-input" rows="2" placeholder="What was the overtime for?"></textarea>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">Save</span><span wire:loading wire:target="save" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button>
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
