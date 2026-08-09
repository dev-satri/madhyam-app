<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Expense;
use App\Support\UserVisibility;
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

        $base = DB::table('expenses')->whereNull('deleted_at');
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
            ->whereNull('deleted_at')
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
            ->leftJoin('users', 'expenses.staff_member_id', '=', 'users.id')
            ->whereNull('expenses.deleted_at');

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
            $e = DB::table('expenses')->whereNull('deleted_at')->where('id', $id)->first();
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
        $e = DB::table('expenses')->whereNull('deleted_at')->where('id', $id)->first();
        if ($e) {
            $newStatus = $e->status === 'paid' ? 'pending' : 'paid';
            DB::table('expenses')->where('id', $id)->update(['status' => $newStatus, 'updated_at' => now()]);
            $this->dispatch('toast', message: 'Status updated', type: 'success');
        }
    }

    public function deleteExpense(int $id): void
    {
        Expense::findOrFail($id)->delete();
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
        return DB::table('clients')->whereNull('deleted_at')->orderBy('name')->get();
    }

    #[Computed]
    public function team()
    {
        return UserVisibility::apply(
            DB::table('users')->where('status', 'active')
        )->orderBy('name')->get();
    }

    #[Computed]
    public function months(): array
    {
        return DB::table('expenses')
            ->whereNull('deleted_at')
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

            {{-- Category Breakdown: single stacked bar + legend --}}
            @if(count($this->categoryBreakdown))
                @php
                    $cbTotal = collect($this->categoryBreakdown)->sum('total');
                    $cbHex = [
                        'salary' => '#3b82f6', 'office' => '#f59e0b', 'operations' => '#22c55e',
                        'software' => '#a855f7', 'equipment' => '#ef4444', 'travel' => '#06b6d4',
                        'marketing' => '#ec4899', 'other' => '#94a3b8',
                    ];
                @endphp
                <div class="bg-white rounded-2xl border border-gray-100 p-6">
                    <div class="flex items-baseline justify-between mb-4">
                        <div>
                            <h3 class="font-bold text-sm text-gray-900">Where the money went</h3>
                            <p class="text-xs text-gray-400 mt-0.5">{{ $this->monthFilter ? \App\Support\NepaliDate::displayMonthYear(\Illuminate\Support\Carbon::createFromFormat('Y-m', $this->monthFilter)) : \App\Support\NepaliDate::displayMonthYear(now()) }} · {{ count($this->categoryBreakdown) }} categor{{ count($this->categoryBreakdown) === 1 ? 'y' : 'ies' }}</p>
                        </div>
                        <div class="text-right">
                            <div class="text-xs uppercase tracking-wider text-gray-400">Total</div>
                            <div class="text-lg font-bold text-gray-900">{{ fmtCurrency($cbTotal) }}</div>
                        </div>
                    </div>
                    {{-- The stacked bar --}}
                    <div class="flex h-3 w-full overflow-hidden rounded-full bg-gray-100">
                        @foreach($this->categoryBreakdown as $cb)
                            <div class="h-full transition-all duration-300 hover:opacity-80" style="width: {{ $cb['pct'] }}%; background-color: {{ $cbHex[$cb['category']] ?? '#94a3b8' }};" title="{{ $cb['label'] }} · {{ $cb['pct'] }}%"></div>
                        @endforeach
                    </div>
                    {{-- Legend --}}
                    <div class="mt-5 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach($this->categoryBreakdown as $cb)
                            <button type="button" wire:click="$set('categoryFilter', '{{ $cb['category'] }}')" class="group flex items-center justify-between text-left transition hover:bg-gray-50 rounded-lg -mx-2 px-2 py-1">
                                <span class="flex items-center gap-2 min-w-0">
                                    <span class="h-2.5 w-2.5 rounded-full flex-shrink-0" style="background-color: {{ $cbHex[$cb['category']] ?? '#94a3b8' }};"></span>
                                    <span class="text-sm text-gray-700 font-medium truncate">{{ $cb['label'] }}</span>
                                    <span class="text-[10px] text-gray-400">{{ $cb['pct'] }}%</span>
                                </span>
                                <span class="text-sm font-semibold text-gray-900 tabular-nums">{{ fmtCurrency($cb['total']) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Search + Filter chip drawer --}}
            <div x-data="{ open: {{ $this->hasActiveFilters ? 'true' : 'false' }} }" class="space-y-3">
                <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
                    <x-search-input wire="search" placeholder="Search description, vendor, item..." class="flex-1" />
                    <button type="button" @click="open = !open" class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:border-gray-300 hover:bg-gray-50">
                        <i class="fas fa-sliders-h text-xs text-gray-400"></i>
                        Filters
                        @php
                            $activeCount = ($this->categoryFilter ? 1 : 0) + ($this->statusFilter ? 1 : 0) + ($this->monthFilter ? 1 : 0) + ($this->clientFilter ? 1 : 0);
                        @endphp
                        @if($activeCount)
                            <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[var(--brand)] px-1.5 text-[10px] font-bold text-white">{{ $activeCount }}</span>
                        @endif
                        <i class="fas fa-chevron-down text-[10px] text-gray-400 transition" :class="open && 'rotate-180'"></i>
                    </button>
                </div>

                {{-- Active filter chips (always visible when set) --}}
                @if($this->hasActiveFilters)
                    <div class="flex flex-wrap items-center gap-2">
                        @if($this->categoryFilter)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-3 pr-1 text-xs font-medium text-gray-700">
                                <span class="text-gray-400">Category:</span> {{ ucfirst($this->categoryFilter) }}
                                <button type="button" wire:click="$set('categoryFilter', '')" class="inline-flex h-5 w-5 items-center justify-center rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-700"><i class="fas fa-times text-[9px]"></i></button>
                            </span>
                        @endif
                        @if($this->statusFilter)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-3 pr-1 text-xs font-medium text-gray-700">
                                <span class="text-gray-400">Status:</span> {{ ucfirst($this->statusFilter) }}
                                <button type="button" wire:click="$set('statusFilter', '')" class="inline-flex h-5 w-5 items-center justify-center rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-700"><i class="fas fa-times text-[9px]"></i></button>
                            </span>
                        @endif
                        @if($this->monthFilter)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-3 pr-1 text-xs font-medium text-gray-700">
                                <span class="text-gray-400">Month:</span> {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->monthFilter)->format('M Y') }}
                                <button type="button" wire:click="$set('monthFilter', '')" class="inline-flex h-5 w-5 items-center justify-center rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-700"><i class="fas fa-times text-[9px]"></i></button>
                            </span>
                        @endif
                        @if($this->clientFilter)
                            @php $selectedClient = collect($this->clients)->firstWhere('id', $this->clientFilter); @endphp
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-3 pr-1 text-xs font-medium text-gray-700">
                                <span class="text-gray-400">Client:</span> {{ $selectedClient->name ?? '#' . $this->clientFilter }}
                                <button type="button" wire:click="$set('clientFilter', 0)" class="inline-flex h-5 w-5 items-center justify-center rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-700"><i class="fas fa-times text-[9px]"></i></button>
                            </span>
                        @endif
                        <button type="button" wire:click="clearFilters" class="text-xs font-medium text-gray-400 hover:text-gray-700 ml-1">Clear all</button>
                    </div>
                @endif

                {{-- Collapsible filter drawer --}}
                <div x-show="open" x-cloak x-transition class="rounded-2xl border border-gray-100 bg-white p-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="form-label">Category</label>
                            <select wire:model.live="categoryFilter" class="form-select">
                                <option value="">All Categories</option>
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
                            <label class="form-label">Status</label>
                            <select wire:model.live="statusFilter" class="form-select">
                                <option value="">All Status</option>
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Month</label>
                            <input type="month" wire:model.live="monthFilter" class="form-input">
                        </div>
                        <div>
                            <label class="form-label">Client</label>
                            <select wire:model.live="clientFilter" class="form-select">
                                <option value="">All Clients</option>
                                @foreach($this->clients as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Filter summary --}}
            @if($this->hasActiveFilters)
                <div class="text-xs text-gray-500">
                    Showing {{ $this->expenses->total() }} expenses · Total: {{ fmtCurrency($this->expenses->sum('amount')) }}
                </div>
            @endif

            {{-- Slim table --}}
            <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60">
                                <th class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 px-5 py-3">Date</th>
                                <th class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 px-5 py-3">Expense</th>
                                <th class="text-right text-[11px] font-semibold uppercase tracking-wider text-gray-500 px-5 py-3">Amount</th>
                                <th class="w-24"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $catHex = [
                                    'salary' => '#3b82f6', 'office' => '#f59e0b', 'operations' => '#22c55e',
                                    'software' => '#a855f7', 'equipment' => '#ef4444', 'travel' => '#06b6d4',
                                    'marketing' => '#ec4899', 'other' => '#94a3b8',
                                ];
                                $catIcons = [
                                    'salary' => 'fa-users', 'office' => 'fa-building', 'operations' => 'fa-cogs',
                                    'software' => 'fa-laptop-code', 'equipment' => 'fa-tools', 'travel' => 'fa-plane',
                                    'marketing' => 'fa-bullhorn', 'other' => 'fa-ellipsis-h',
                                ];
                                $methodIcons = [
                                    'cash' => 'fa-money-bill-wave', 'bank' => 'fa-university',
                                    'card' => 'fa-credit-card', 'cheque' => 'fa-file-invoice',
                                ];
                            @endphp
                            @forelse($this->expenses as $e)
                                @php
                                    $metaLine = match($e->category) {
                                        'office' => $e->location,
                                        'equipment' => $e->item_name,
                                        'travel' => $e->destination,
                                        default => null,
                                    };
                                    $catColor = $catHex[$e->category] ?? '#94a3b8';
                                    $catIcon = $catIcons[$e->category] ?? 'fa-ellipsis-h';
                                    $methodIcon = $methodIcons[$e->payment_method] ?? 'fa-wallet';
                                @endphp
                                <tr x-data="{ open: false }" class="group hover:bg-gray-50/50 transition">
                                    <td class="px-5 py-4 whitespace-nowrap align-top">
                                        <div class="text-sm font-medium text-gray-900">{{ \App\Support\NepaliDate::displayShort($e->date) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-start gap-3">
                                            <span class="inline-flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl" style="background-color: {{ $catColor }}1a; color: {{ $catColor }};">
                                                <i class="fas {{ $catIcon }} text-xs"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <button type="button" @click="open = !open" class="text-sm font-semibold text-gray-900 hover:text-[var(--brand)] text-left truncate">{{ $e->description ?: 'Untitled expense' }}</button>
                                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color: {{ $catColor }};">{{ $e->category }}</span>
                                                </div>
                                                <div class="mt-1 flex items-center gap-3 text-xs text-gray-500 flex-wrap">
                                                    @if($e->paid_to)
                                                        <span class="inline-flex items-center gap-1"><i class="fas fa-user text-[9px] text-gray-400"></i>{{ $e->paid_to }}</span>
                                                    @endif
                                                    @if($e->client_name)
                                                        <span class="inline-flex items-center gap-1"><i class="fas fa-briefcase text-[9px] text-gray-400"></i>{{ $e->client_name }}</span>
                                                    @endif
                                                    <span class="inline-flex items-center gap-1"><i class="fas {{ $methodIcon }} text-[9px] text-gray-400"></i>{{ ucfirst($e->payment_method) }}</span>
                                                    @if($metaLine)
                                                        <span class="inline-flex items-center gap-1 text-gray-400"><i class="fas fa-map-marker-alt text-[9px]"></i>{{ $metaLine }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-right align-top whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900 tabular-nums">{{ fmtCurrency($e->amount) }}</div>
                                        <button wire:click="toggleStatus({{ $e->id }})" class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider transition hover:opacity-80 {{ $e->status === 'paid' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' }}">
                                            <span class="h-1.5 w-1.5 rounded-full {{ $e->status === 'paid' ? 'bg-green-500' : 'bg-amber-500' }}"></span>
                                            {{ $e->status }}
                                        </button>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <div class="flex items-center justify-end gap-1">
                                            <button wire:click="openForm({{ $e->id }})" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50" title="Edit">
                                                <i class="fas fa-pen text-[10px]"></i>
                                                <span class="hidden sm:inline">Edit</span>
                                            </button>
                                            <div x-data="{ menuOpen: false }" class="relative">
                                                <button type="button" @click="menuOpen = !menuOpen" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700" aria-label="More actions">
                                                    <i class="fas fa-ellipsis-v text-xs"></i>
                                                </button>
                                                <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false" @keydown.escape.window="menuOpen = false" x-transition style="display:none;" class="absolute right-0 top-full z-20 mt-1 w-44 overflow-hidden rounded-xl border border-gray-100 bg-white py-1 shadow-lg">
                                                    <button type="button" wire:click="toggleStatus({{ $e->id }})" @click="menuOpen = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50">
                                                        <i class="fas {{ $e->status === 'paid' ? 'fa-undo' : 'fa-check' }} w-3 text-gray-400"></i>
                                                        Mark as {{ $e->status === 'paid' ? 'pending' : 'paid' }}
                                                    </button>
                                                    <button type="button" wire:click="openForm({{ $e->id }})" @click="menuOpen = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50">
                                                        <i class="fas fa-pen w-3 text-gray-400"></i>
                                                        Edit expense
                                                    </button>
                                                    <div class="my-1 border-t border-gray-100"></div>
                                                    <button type="button" @click="menuOpen = false" wire:click="$dispatch('open-confirm', { title: 'Delete Expense?', message: 'This expense entry will be permanently removed.', type: 'danger', action: 'deleteExpense', params: [{{ $e->id }}] })" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-red-600 hover:bg-red-50">
                                                        <i class="fas fa-trash w-3"></i>
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <div class="py-16 text-center">
                                            <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"><i class="fas fa-receipt text-2xl text-gray-300"></i></div>
                                            <p class="text-gray-500 font-medium text-sm">No expenses found</p>
                                            <p class="text-gray-400 text-xs mt-1">{{ $this->hasActiveFilters ? 'Try adjusting your filters' : 'Track your first expense to get started' }}</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($this->expenses->count())
                            <tfoot>
                                <tr class="border-t border-gray-100 bg-gray-50/60">
                                    <td colspan="2" class="px-5 py-3 text-xs font-medium text-gray-500">Total ({{ $this->expenses->total() }} expenses on this page)</td>
                                    <td class="px-5 py-3 text-right text-sm font-bold text-gray-900 tabular-nums">{{ fmtCurrency($this->expenses->sum('amount')) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
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
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                    <x-date-input model="formDate" name="formDate" />
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
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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

    #[On('confirm-resolved')]
    public function onConfirmResolved(string $action, array $params = []): void
    {
        if ($action !== '' && method_exists($this, $action)) {
            $this->{$action}(...$params);
        }
    }
};
