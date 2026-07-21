<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;
    public string $search = '';
    public string $categoryFilter = '';
    public string $statusFilter = '';
    public string $monthFilter = '';
    public int $clientFilter = 0;
    public bool $showForm = false;
    public int $editingId = 0;

    // Form fields
    public string $formCategory = 'other';
    public string $formDate = '';
    public string $formDescription = '';
    public float $formAmount = 0;
    public string $formPaidTo = '';
    public int $formClientId = 0;
    public string $formPaymentMethod = 'cash';
    public string $formStatus = 'pending';
    public int $formStaffMemberId = 0;
    public string $formLocation = '';
    public string $formItemName = '';
    public string $formDestination = '';

    public function mount(): void
    {
        $this->formDate = now()->format('Y-m-d');
    }

    public function getStats(): array
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $base = DB::table('expenses');
        $monthQ = (clone $base)->whereBetween('date', [$startOfMonth, $endOfMonth]);

        $total = (clone $monthQ)->sum('amount');
        $paid = (clone $monthQ)->where('status', 'paid')->sum('amount');
        $pending = (clone $monthQ)->where('status', 'pending')->sum('amount');
        $categories = (clone $base)->distinct('category')->count('category');

        return [
            'total' => $total,
            'paid' => $paid,
            'pending' => $pending,
            'categories' => $categories,
        ];
    }

    public function getCategoryBreakdown(): array
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $monthFilter = $this->monthFilter;
        if ($monthFilter) {
            $startOfMonth = \Illuminate\Support\Carbon::createFromDate(substr($monthFilter, 0, 4), substr($monthFilter, 5, 2), 1)->startOfMonth();
            $endOfMonth = $startOfMonth->copy()->endOfMonth();
        }

        $rows = DB::table('expenses')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderBy('total', 'desc')
            ->get();

        $grandTotal = $rows->sum('total');
        $colors = [
            'salary' => ['bg' => 'bg-blue-500', 'text' => 'text-blue-600'],
            'office' => ['bg' => 'bg-amber-500', 'text' => 'text-amber-600'],
            'operations' => ['bg' => 'bg-green-500', 'text' => 'text-green-600'],
            'software' => ['bg' => 'bg-purple-500', 'text' => 'text-purple-600'],
            'equipment' => ['bg' => 'bg-red-500', 'text' => 'text-red-600'],
            'travel' => ['bg' => 'bg-cyan-500', 'text' => 'text-cyan-600'],
            'marketing' => ['bg' => 'bg-pink-500', 'text' => 'text-pink-600'],
            'other' => ['bg' => 'bg-gray-400', 'text' => 'text-gray-600'],
        ];
        $icons = [
            'salary' => 'fa-users', 'office' => 'fa-building', 'operations' => 'fa-cogs',
            'software' => 'fa-laptop-code', 'equipment' => 'fa-tools', 'travel' => 'fa-plane',
            'marketing' => 'fa-bullhorn', 'other' => 'fa-ellipsis-h',
        ];

        return $rows->map(fn($r) => [
            'category' => $r->category,
            'label' => ucfirst($r->category),
            'total' => $r->total,
            'pct' => $grandTotal > 0 ? round(($r->total / $grandTotal) * 100, 1) : 0,
            'color' => $colors[$r->category] ?? $colors['other'],
            'icon' => $icons[$r->category] ?? 'fa-circle',
        ])->toArray();
    }

    public function getExpenses()
    {
        $q = DB::table('expenses')
            ->leftJoin('clients', 'expenses.client_id', '=', 'clients.id')
            ->leftJoin('users', 'expenses.staff_member_id', '=', 'users.id');

        if ($this->search) {
            $q->where(function ($sub) {
                $sub->where('expenses.description', 'like', "%{$this->search}%")
                    ->orWhere('expenses.paid_to', 'like', "%{$this->search}%")
                    ->orWhere('expenses.item_name', 'like', "%{$this->search}%")
                    ->orWhere('expenses.destination', 'like', "%{$this->search}%")
                    ->orWhere('expenses.location', 'like', "%{$this->search}%");
            });
        }

        if ($this->categoryFilter) $q->where('expenses.category', $this->categoryFilter);
        if ($this->statusFilter) $q->where('expenses.status', $this->statusFilter);

        if ($this->monthFilter) {
            $q->whereRaw('YEAR(expenses.date) = ?', [substr($this->monthFilter, 0, 4)])
              ->whereRaw('MONTH(expenses.date) = ?', [substr($this->monthFilter, 5, 2)]);
        }

        if ($this->clientFilter) $q->where('expenses.client_id', $this->clientFilter);

        return $q->select(
                'expenses.*',
                'clients.name as client_name',
                'users.name as staff_name'
            )
            ->orderBy('expenses.date', 'desc')
            ->paginate(50);
    }

    public function openForm(?int $id = null): void
    {
        if ($id) {
            $e = DB::table('expenses')->where('id', $id)->first();
            if ($e) {
                $this->editingId = $id;
                $this->formCategory = $e->category;
                $this->formDate = $e->date;
                $this->formDescription = $e->description ?? '';
                $this->formAmount = (float) $e->amount;
                $this->formPaidTo = $e->paid_to ?? '';
                $this->formClientId = $e->client_id ?? 0;
                $this->formPaymentMethod = $e->payment_method ?? 'cash';
                $this->formStatus = $e->status ?? 'pending';
                $this->formStaffMemberId = $e->staff_member_id ?? 0;
                $this->formLocation = $e->location ?? '';
                $this->formItemName = $e->item_name ?? '';
                $this->formDestination = $e->destination ?? '';
            }
        } else {
            $this->editingId = 0;
            $this->formCategory = 'other';
            $this->formDate = now()->format('Y-m-d');
            $this->formDescription = '';
            $this->formAmount = 0;
            $this->formPaidTo = '';
            $this->formClientId = 0;
            $this->formPaymentMethod = 'cash';
            $this->formStatus = 'pending';
            $this->formStaffMemberId = 0;
            $this->formLocation = '';
            $this->formItemName = '';
            $this->formDestination = '';
        }
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'formCategory' => 'required|in:office,operations,software,equipment,travel,marketing,other',
            'formDate' => 'required|date',
            'formAmount' => 'required|numeric|min:0',
        ]);

        $data = [
            'category' => $this->formCategory,
            'date' => $this->formDate,
            'description' => $this->formDescription ?: null,
            'amount' => $this->formAmount,
            'paid_to' => $this->formPaidTo ?: null,
            'client_id' => $this->formClientId ?: null,
            'payment_method' => $this->formPaymentMethod,
            'status' => $this->formStatus,
            // staff_member_id retained on the schema for legacy rows but no
            // longer writable from Expenses — payroll flows through Salary.
            'staff_member_id' => null,
            'location' => $this->formCategory === 'office' ? ($this->formLocation ?: null) : null,
            'item_name' => $this->formCategory === 'equipment' ? ($this->formItemName ?: null) : null,
            'destination' => $this->formCategory === 'travel' ? ($this->formDestination ?: null) : null,
        ];

        if ($this->editingId) {
            DB::table('expenses')->where('id', $this->editingId)->update($data);
        } else {
            $data['created_by'] = Auth::id();
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('expenses')->insert($data);
        }

        $this->showForm = false;
        $this->dispatch('toast', message: 'Expense saved', type: 'success');
    }

    public function toggleStatus(int $id): void
    {
        $e = DB::table('expenses')->where('id', $id)->first();
        if ($e) {
            $newStatus = $e->status === 'paid' ? 'pending' : 'paid';
            DB::table('expenses')->where('id', $id)->update(['status' => $newStatus, 'updated_at' => now()]);
            $this->dispatch('toast', message: 'Status updated', type: 'success');
        }
    }

    public function deleteExpense(int $id): void
    {
        DB::table('expenses')->where('id', $id)->delete();
        $this->dispatch('toast', message: 'Expense deleted', type: 'success');
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->categoryFilter = '';
        $this->statusFilter = '';
        $this->monthFilter = '';
        $this->clientFilter = 0;
    }

    #[Computed]
    public function stats(): array { return $this->getStats(); }

    #[Computed]
    public function categoryBreakdown(): array { return $this->getCategoryBreakdown(); }

    #[Computed]
    public function expenses() { return $this->getExpenses(); }

    #[Computed]
    public function clients()
    {
        return DB::table('clients')->orderBy('name')->get();
    }

    #[Computed]
    public function team()
    {
        return DB::table('users')->where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function months(): array
    {
        return DB::table('expenses')
            ->selectRaw('DISTINCT DATE_FORMAT(date, "%Y-%m") as month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->pluck('month')
            ->toArray();
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->search || $this->categoryFilter || $this->statusFilter || $this->monthFilter || $this->clientFilter;
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Expenses</h1>
                    <p class="text-sm text-gray-500 mt-1">Track and manage expenses</p>
                </div>
                <button wire:click="openForm" class="btn btn-primary"><i class="fas fa-plus text-sm"></i> Add Expense</button>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-receipt"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['total']) }}</div><div class="stat-label">This Month</div></div>
                <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['paid']) }}</div><div class="stat-label">Paid</div></div>
                <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ fmtCurrency($this->stats['pending']) }}</div><div class="stat-label">Pending</div></div>
                <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-layer-group"></i></div><div class="stat-value">{{ $this->stats['categories'] }}</div><div class="stat-label">Categories</div></div>
            </div>

            {{-- Category Breakdown --}}
            @if(count($this->categoryBreakdown))
                <div class="bg-white rounded-2xl border border-gray-100 p-5">
                    <h3 class="font-bold text-sm mb-4">Category Breakdown</h3>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                        @foreach($this->categoryBreakdown as $cb)
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="flex items-center gap-2 text-sm font-medium">
                                        <i class="fas {{ $cb['icon'] }} {{ $cb['color']['text'] }}"></i>
                                        {{ $cb['label'] }}
                                    </span>
                                    <span class="text-xs font-semibold text-gray-600">{{ $cb['pct'] }}%</span>
                                </div>
                                <div class="progress-bar"><div class="progress-fill {{ $cb['color']['bg'] }}" style="width: {{ $cb['pct'] }}%"></div></div>
                                <div class="text-xs text-gray-500 text-right">{{ fmtCurrency($cb['total']) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Filters --}}
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 items-end">
                <div><label class="form-label">Search</label><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="search" wire:model.live.debounce.250ms="search" placeholder="Search description, vendor, item..." class="form-input pl-10"></div></div>
                <div><label class="form-label">Category</label><select wire:model.live="categoryFilter" class="form-select">
                    <option value="">All Categories</option>
                    <option value="office">Office</option>
                    <option value="operations">Operations</option>
                    <option value="software">Software</option>
                    <option value="equipment">Equipment</option>
                    <option value="travel">Travel</option>
                    <option value="marketing">Marketing</option>
                    <option value="other">Other</option>
                </select></div>
                <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select">
                    <option value="">All Status</option>
                    <option value="paid">Paid</option>
                    <option value="pending">Pending</option>
                </select></div>
                <div><label class="form-label">Month</label><input type="month" wire:model.live="monthFilter" class="form-input"></div>
                <div><label class="form-label">Client</label><select wire:model.live="clientFilter" class="form-select">
                    <option value="">All Clients</option>
                    @foreach($this->clients as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select></div>
            </div>
            @if($this->hasActiveFilters)
                <div class="flex justify-end"><button wire:click="clearFilters" class="btn btn-ghost btn-sm text-gray-500"><i class="fas fa-times text-sm"></i> Clear filters</button></div>
            @endif

            {{-- Filter summary --}}
            @if($this->hasActiveFilters)
                <div class="text-xs text-gray-500">
                    Showing {{ $this->expenses->total() }} expenses · Total: {{ fmtCurrency($this->expenses->sum('amount')) }}
                </div>
            @endif

            {{-- Table --}}
            <div class="overflow-x-auto">
                <table class="data-table w-full">
                    <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Client</th><th class="text-right">Amount</th><th>Paid To</th><th>Method</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse($this->expenses as $e)
                            <tr>
                                <td class="text-sm whitespace-nowrap">{{ fmtDate($e->date) }}</td>
                                <td>
                                    <div class="font-medium text-sm">{{ $e->description ?: '-' }}</div>
                                    @php
                                        $metaLine = match($e->category) {
                                            'office' => $e->location ?? '-',
                                            'equipment' => $e->item_name ?? '-',
                                            'travel' => $e->destination ?? '-',
                                            default => '',
                                        };
                                    @endphp
                                    @if($metaLine)
                                        <div class="text-xs text-gray-400">{{ $metaLine }}</div>
                                    @endif
                                </td>
                                <td><span class="badge {{ match($e->category) {
                                    'office' => 'bg-amber-100 text-amber-700',
                                    'operations' => 'bg-green-100 text-green-700',
                                    'software' => 'bg-purple-100 text-purple-700',
                                    'equipment' => 'bg-red-100 text-red-700',
                                    'travel' => 'bg-cyan-100 text-cyan-700',
                                    'marketing' => 'bg-pink-100 text-pink-700',
                                    default => 'bg-gray-100 text-gray-700',
                                } }}"><i class="fas {{ match($e->category) {
                                    'office' => 'fa-building',
                                    'operations' => 'fa-cogs',
                                    'software' => 'fa-laptop-code',
                                    'equipment' => 'fa-tools',
                                    'travel' => 'fa-plane',
                                    'marketing' => 'fa-bullhorn',
                                    default => 'fa-ellipsis-h',
                                } }} text-[10px]"></i> {{ ucfirst($e->category) }}</span></td>
                                <td class="text-sm">{{ $e->client_name ?? '-' }}</td>
                                <td class="text-right font-semibold">{{ fmtCurrency($e->amount) }}</td>
                                <td class="text-sm text-gray-600">{{ $e->paid_to ?? '-' }}</td>
                                <td><i class="fas {{ match($e->payment_method) {
                                    'cash' => 'fa-money-bill-wave',
                                    'bank' => 'fa-university',
                                    'card' => 'fa-credit-card',
                                    'cheque' => 'fa-file-invoice',
                                    default => 'fa-wallet',
                                } }} text-gray-400 text-sm"></i></td>
                                <td>
                                    <button wire:click="toggleStatus({{ $e->id }})" class="badge {{ $e->status === 'paid' ? 'badge-paid' : 'badge-pending' }} cursor-pointer hover:opacity-80">
                                        {{ ucfirst($e->status) }}
                                    </button>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1">
                                        <button wire:click="openForm({{ $e->id }})" class="btn btn-icon btn-ghost" title="Edit"><i class="fas fa-pen text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                        <button wire:click="deleteExpense({{ $e->id }})" wire:confirm="Are you sure you want to delete this expense?" class="btn btn-icon btn-ghost" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9">
                                <div class="py-16 text-center">
                                    <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"><i class="fas fa-receipt text-2xl text-gray-300"></i></div>
                                    <p class="text-gray-500 font-medium text-sm">No expenses found</p>
                                    <p class="text-gray-400 text-xs mt-1">Track your first expense to get started</p>
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                    @if($this->expenses->count())
                        <tfoot>
                            <tr class="font-bold bg-gray-50">
                                <td colspan="4" class="text-sm">Total ({{ $this->expenses->total() }} expenses)</td>
                                <td class="text-right text-green-600">{{ fmtCurrency($this->expenses->sum('amount')) }}</td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            <div class="mt-4">
                {{ $this->expenses->links() }}
            </div>

            {{-- Expense Form Modal --}}
            @if($showForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showForm', false)" x-on:keydown.escape.window="$wire.set('showForm', false)">
                    <div class="modal-box w-full max-w-lg mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingId ? 'Edit' : 'Add' }} Expense</h3>
                            <button wire:click="$set('showForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Category</label>
                                    <select wire:model.live="formCategory" class="form-select">
                                        <option value="office">Office</option>
                                        <option value="operations">Operations</option>
                                        <option value="software">Software</option>
                                        <option value="equipment">Equipment</option>
                                        <option value="travel">Travel</option>
                                        <option value="marketing">Marketing</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Date</label>
                                    <input type="date" wire:model="formDate" class="form-input">
                                    <span wire:error="formDate" class="text-red-500 text-xs mt-1 block"></span>
                                </div>
                            </div>

                            {{-- Category-specific extra fields --}}
                            {{-- Salary category removed: payroll flows through the Salary module. --}}
                            @if($formCategory === 'office')
                                <div>
                                    <label class="form-label">Location / Department</label>
                                    <input type="text" wire:model="formLocation" class="form-input" placeholder="e.g. Main Office, Marketing Dept">
                                </div>
                            @endif
                            @if($formCategory === 'equipment')
                                <div>
                                    <label class="form-label">Item Name</label>
                                    <input type="text" wire:model="formItemName" class="form-input" placeholder="e.g. Tripod, Camera Lens">
                                </div>
                            @endif
                            @if($formCategory === 'travel')
                                <div>
                                    <label class="form-label">Destination</label>
                                    <input type="text" wire:model="formDestination" class="form-input" placeholder="e.g. Kathmandu Office">
                                </div>
                            @endif

                            <div>
                                <label class="form-label">Description</label>
                                <input type="text" wire:model="formDescription" class="form-input" placeholder="Expense description">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Amount</label>
                                    <input type="number" wire:model="formAmount" class="form-input" step="0.01" min="0">
                                    <span wire:error="formAmount" class="text-red-500 text-xs mt-1 block"></span>
                                </div>
                                <div>
                                    <label class="form-label">Paid To</label>
                                    <input type="text" wire:model="formPaidTo" class="form-input" placeholder="Vendor / Payee">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Client (optional)</label>
                                    <select wire:model="formClientId" class="form-select">
                                        <option value="">None</option>
                                        @foreach($this->clients as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Payment Method</label>
                                    <select wire:model="formPaymentMethod" class="form-select">
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="card">Card</option>
                                        <option value="cheque">Cheque</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Status</label>
                                <select wire:model="formStatus" class="form-select">
                                    <option value="pending">Pending</option>
                                    <option value="paid">Paid</option>
                                </select>
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
};
