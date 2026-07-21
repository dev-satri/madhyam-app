<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Services\PackageService;
use Carbon\Carbon;

new #[Layout('components.layouts.app')] class extends Component
{
    public array $packages = [];
    public array $allClientsUsage = [];

    // Package form
    public bool $showPkgForm = false;
    public int $editingPkgId = 0;
    public string $pkgName = '';
    public string $pkgSlug = '';
    public float $pkgAmount = 0;
    public string $pkgDeliverables = '';
    public string $pkgFeatures = '';
    public string $pkgStatus = 'active';
    public int $pkgContentLimit = 30;
    public int $pkgWorkflowLimit = 20;
    public int $pkgStorageLimit = 1024;
    public int $pkgRevisionLimit = 3;
    public bool $pkgPrioritySupport = false;
    public string $pkgPlatforms = '';

    // Detail modal
    public bool $showDetailModal = false;
    public array $detailPackage = [];
    public array $detailClients = [];
    public string $detailSearch = '';

    // Client usage filter
    public string $clientFilter = 'all';
    public string $packageFilter = 'all';
    public string $clientSearch = '';
    public string $sortBy = 'client_name';
    public bool $sortDesc = false;

    // Client detail modal
    public bool $showClientModal = false;
    public array $clientDetail = [];

    // View toggle
    public string $viewMode = 'table'; // table or grid

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->packages = PackageService::getPackageStats();
        $this->allClientsUsage = PackageService::getAllClientsUsage();
    }

    public function resetFilters(): void
    {
        $this->clientFilter = 'all';
        $this->packageFilter = 'all';
        $this->clientSearch = '';
        $this->sortBy = 'client_name';
        $this->sortDesc = false;
    }

    // ── Package CRUD ──

    public function openPkgForm(?int $id = null): void
    {
        $this->resetValidation();
        if ($id) {
            $pkg = DB::table('packages')->where('id', $id)->first();
            if (!$pkg) return;
            $this->editingPkgId = $pkg->id;
            $this->pkgName = $pkg->name;
            $this->pkgSlug = $pkg->slug;
            $this->pkgAmount = (float) $pkg->monthly_amount;
            $this->pkgDeliverables = $pkg->deliverables ?? '';
            $this->pkgFeatures = is_array(json_decode($pkg->features ?? '[]', true)) ? implode("\n", json_decode($pkg->features ?? '[]', true)) : '';
            $this->pkgStatus = $pkg->status ?? 'active';
            $this->pkgContentLimit = (int) $pkg->content_limit;
            $this->pkgWorkflowLimit = (int) $pkg->workflow_limit;
            $this->pkgStorageLimit = (int) $pkg->storage_limit_mb;
            $this->pkgRevisionLimit = (int) $pkg->revision_limit;
            $this->pkgPrioritySupport = (bool) $pkg->priority_support;
            $this->pkgPlatforms = is_array(json_decode($pkg->included_platforms ?? '[]', true)) ? implode(', ', json_decode($pkg->included_platforms ?? '[]', true)) : '';
        } else {
            $this->editingPkgId = 0;
            $this->pkgName = '';
            $this->pkgSlug = '';
            $this->pkgAmount = 0;
            $this->pkgDeliverables = '';
            $this->pkgFeatures = '';
            $this->pkgStatus = 'active';
            $this->pkgContentLimit = 30;
            $this->pkgWorkflowLimit = 20;
            $this->pkgStorageLimit = 1024;
            $this->pkgRevisionLimit = 3;
            $this->pkgPrioritySupport = false;
            $this->pkgPlatforms = '';
        }
        $this->showPkgForm = true;
    }

    public function savePkg(): void
    {
        $this->validate([
            'pkgName' => 'required|string|max:255',
            'pkgSlug' => 'required|string|max:100|alpha_dash',
            'pkgAmount' => 'required|numeric|min:0',
        ]);

        $features = array_filter(array_map('trim', explode("\n", $this->pkgFeatures)));
        $platforms = array_filter(array_map('trim', explode(',', $this->pkgPlatforms)));

        $data = [
            'name' => $this->pkgName,
            'slug' => $this->pkgSlug,
            'monthly_amount' => $this->pkgAmount,
            'deliverables' => $this->pkgDeliverables,
            'features' => json_encode($features),
            'status' => $this->pkgStatus,
            'content_limit' => $this->pkgContentLimit,
            'workflow_limit' => $this->pkgWorkflowLimit,
            'storage_limit_mb' => $this->pkgStorageLimit,
            'revision_limit' => $this->pkgRevisionLimit,
            'priority_support' => $this->pkgPrioritySupport,
            'included_platforms' => json_encode($platforms),
            'updated_at' => now(),
        ];

        if ($this->editingPkgId) {
            DB::table('packages')->where('id', $this->editingPkgId)->update($data);
            $this->dispatch('toast', message: 'Package updated', type: 'success');
        } else {
            $data['created_at'] = now();
            DB::table('packages')->insert($data);
            $this->dispatch('toast', message: 'Package created', type: 'success');
        }

        $this->showPkgForm = false;
        $this->loadData();
    }

    public function deletePkg(int $id): void
    {
        $clientCount = DB::table('clients')->where('package', DB::table('packages')->where('id', $id)->value('slug'))->count();
        if ($clientCount > 0) {
            $this->dispatch('toast', message: 'Cannot delete: ' . $clientCount . ' client(s) using this package', type: 'error');
            return;
        }
        DB::table('packages')->where('id', $id)->delete();
        $this->dispatch('toast', message: 'Package deleted', type: 'success');
        $this->loadData();
    }

    // ── Package Detail Modal ──

    public function openDetail(int $pkgId): void
    {
        $pkg = DB::table('packages')->where('id', $pkgId)->first();
        if (!$pkg) return;

        $this->detailPackage = [
            'id' => $pkg->id,
            'name' => $pkg->name,
            'slug' => $pkg->slug,
            'monthly_amount' => (float) $pkg->monthly_amount,
            'content_limit' => (int) $pkg->content_limit,
            'workflow_limit' => (int) $pkg->workflow_limit,
            'storage_limit_mb' => (int) $pkg->storage_limit_mb,
            'revision_limit' => (int) $pkg->revision_limit,
            'priority_support' => (bool) $pkg->priority_support,
            'included_platforms' => json_decode($pkg->included_platforms ?? '[]', true),
            'features' => json_decode($pkg->features ?? '[]', true),
            'deliverables' => $pkg->deliverables,
        ];

        $clients = DB::table('clients')
            ->where('package', $pkg->slug)
            ->where('status', 'active')
            ->get();

        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');
        $now = Carbon::now();
        $daysInMonth = $now->daysInMonth;
        $daysPassed = $now->day;
        $daysRemaining = $daysInMonth - $daysPassed;

        $this->detailClients = [];
        foreach ($clients as $client) {
            $usage = PackageService::getUsage($client->id, $month, $year);
            $contentPct = PackageService::getUsagePercent($usage['content_created'] ?? 0, (int) $pkg->content_limit);
            $workflowPct = PackageService::getUsagePercent($usage['workflow_items'] ?? 0, (int) $pkg->workflow_limit);
            $storageUsedMb = round(($usage['storage_used_bytes'] ?? 0) / 1048576, 1);
            $storagePct = PackageService::getUsagePercent($storageUsedMb, (int) $pkg->storage_limit_mb);

            $contentOnTrack = $daysPassed > 0 ? ($usage['content_created'] ?? 0) / $daysPassed * $daysRemaining : 0;
            $workflowOnTrack = $daysPassed > 0 ? ($usage['workflow_items'] ?? 0) / $daysPassed * $daysRemaining : 0;

            $this->detailClients[] = [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'amount' => (float) $client->amount,
                'contract_end' => $client->contract_end ? Carbon::parse($client->contract_end)->format('M d, Y') : 'N/A',
                'contract_days_left' => $client->contract_end ? max(0, Carbon::parse($client->contract_end)->diffInDays(now())) : null,
                'content_used' => $usage['content_created'] ?? 0,
                'content_limit' => (int) $pkg->content_limit,
                'content_pct' => $contentPct,
                'workflow_used' => $usage['workflow_items'] ?? 0,
                'workflow_limit' => (int) $pkg->workflow_limit,
                'workflow_pct' => $workflowPct,
                'storage_used' => $storageUsedMb,
                'storage_limit' => (int) $pkg->storage_limit_mb,
                'storage_pct' => $storagePct,
                'approvals_used' => $usage['approvals_used'] ?? 0,
                'files_uploaded' => $usage['files_uploaded'] ?? 0,
                'days_passed' => $daysPassed,
                'days_remaining' => $daysRemaining,
                'days_in_month' => $daysInMonth,
                'content_projected' => (int) ceil($contentOnTrack),
                'workflow_projected' => (int) ceil($workflowOnTrack),
                'content_status' => $contentPct >= 100 ? 'over' : ($contentPct >= 80 ? 'warning' : 'ok'),
                'workflow_status' => $workflowPct >= 100 ? 'over' : ($workflowPct >= 80 ? 'warning' : 'ok'),
                'storage_status' => $storagePct >= 100 ? 'over' : ($storagePct >= 80 ? 'warning' : 'ok'),
            ];
        }

        $this->detailSearch = '';
        $this->showDetailModal = true;
    }

    public function getFilteredDetailClientsProperty(): array
    {
        if (!$this->detailSearch) return $this->detailClients;
        $q = strtolower($this->detailSearch);
        return array_filter($this->detailClients, fn($c) => str_contains(strtolower($c['client_name']), $q));
    }

    public function getDetailSummaryProperty(): array
    {
        $clients = $this->detailClients;
        $totalRevenue = array_sum(array_column($clients, 'amount'));
        $avgContentPct = count($clients) > 0 ? round(array_sum(array_column($clients, 'content_pct')) / count($clients)) : 0;
        $avgWorkflowPct = count($clients) > 0 ? round(array_sum(array_column($clients, 'workflow_pct')) / count($clients)) : 0;
        $totalOverLimit = count(array_filter($clients, fn($c) => $c['content_status'] === 'over' || $c['workflow_status'] === 'over' || $c['storage_status'] === 'over'));

        return [
            'client_count' => count($clients),
            'total_revenue' => $totalRevenue,
            'avg_content_pct' => $avgContentPct,
            'avg_workflow_pct' => $avgWorkflowPct,
            'total_over_limit' => $totalOverLimit,
        ];
    }

    // ── Client Usage Filtering & Sorting ──

    public function getFilteredClientsProperty(): array
    {
        $clients = $this->allClientsUsage;

        // Search filter
        if ($this->clientSearch) {
            $q = strtolower($this->clientSearch);
            $clients = array_filter($clients, fn($c) => str_contains(strtolower($c['client_name']), $q) || str_contains(strtolower($c['package_name']), $q));
        }

        // Package filter
        if ($this->packageFilter !== 'all') {
            $clients = array_filter($clients, fn($c) => $c['package_slug'] === $this->packageFilter);
        }

        // Status filter
        if ($this->clientFilter === 'over') {
            $clients = array_filter($clients, fn($c) => $c['is_over_limit']);
        } elseif ($this->clientFilter === 'warning') {
            $clients = array_filter($clients, fn($c) => $c['needs_attention'] && !$c['is_over_limit']);
        } elseif ($this->clientFilter === 'ok') {
            $clients = array_filter($clients, fn($c) => !$c['needs_attention']);
        }

        // Sort
        $sortKey = $this->sortBy;
        usort($clients, function($a, $b) use ($sortKey) {
            $valA = $a[$sortKey] ?? '';
            $valB = $b[$sortKey] ?? '';
            if (is_numeric($valA) && is_numeric($valB)) {
                return $this->sortDesc ? $valB <=> $valA : $valA <=> $valB;
            }
            return $this->sortDesc ? strcasecmp($valB, $valA) : strcasecmp($valA, $valB);
        });

        return array_values($clients);
    }

    public function toggleSort(string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDesc = !$this->sortDesc;
        } else {
            $this->sortBy = $field;
            $this->sortDesc = false;
        }
    }

    public function getFilterCountsProperty(): array
    {
        return [
            'all' => count($this->allClientsUsage),
            'over' => count(array_filter($this->allClientsUsage, fn($c) => $c['is_over_limit'])),
            'warning' => count(array_filter($this->allClientsUsage, fn($c) => $c['needs_attention'] && !$c['is_over_limit'])),
            'ok' => count(array_filter($this->allClientsUsage, fn($c) => !$c['needs_attention'])),
        ];
    }

    // ── Client Detail Modal ──

    public function openClientDetail(int $clientId): void
    {
        $client = DB::table('clients')->where('id', $clientId)->first();
        if (!$client) return;

        $pkg = DB::table('packages')->where('slug', $client->package)->first();
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');
        $usage = PackageService::getUsage($clientId, $month, $year);

        $now = Carbon::now();
        $daysInMonth = $now->daysInMonth;
        $daysPassed = $now->day;
        $daysRemaining = $daysInMonth - $daysPassed;

        $contentPct = $pkg ? PackageService::getUsagePercent($usage['content_created'] ?? 0, (int) $pkg->content_limit) : 0;
        $workflowPct = $pkg ? PackageService::getUsagePercent($usage['workflow_items'] ?? 0, (int) $pkg->workflow_limit) : 0;
        $storageUsedMb = round(($usage['storage_used_bytes'] ?? 0) / 1048576, 1);
        $storagePct = $pkg ? PackageService::getUsagePercent($storageUsedMb, (int) $pkg->storage_limit_mb) : 0;

        $this->clientDetail = [
            'id' => $client->id,
            'name' => $client->name,
            'contact' => $client->contact ?? '',
            'email' => $client->email ?? '',
            'phone' => $client->phone ?? '',
            'package_name' => $pkg?->name ?? 'Unknown',
            'package_slug' => $client->package,
            'amount' => (float) $client->amount,
            'contract_start' => $client->contract_start ? Carbon::parse($client->contract_start)->format('M d, Y') : 'N/A',
            'contract_end' => $client->contract_end ? Carbon::parse($client->contract_end)->format('M d, Y') : 'N/A',
            'contract_days_left' => $client->contract_end ? max(0, Carbon::parse($client->contract_end)->diffInDays(now())) : null,
            'content_used' => $usage['content_created'] ?? 0,
            'content_limit' => $pkg ? (int) $pkg->content_limit : 0,
            'content_pct' => $contentPct,
            'content_published' => $usage['content_published'] ?? 0,
            'workflow_used' => $usage['workflow_items'] ?? 0,
            'workflow_limit' => $pkg ? (int) $pkg->workflow_limit : 0,
            'workflow_pct' => $workflowPct,
            'storage_used' => $storageUsedMb,
            'storage_limit' => $pkg ? (int) $pkg->storage_limit_mb : 0,
            'storage_pct' => $storagePct,
            'approvals_used' => $usage['approvals_used'] ?? 0,
            'files_uploaded' => $usage['files_uploaded'] ?? 0,
            'revision_limit' => $pkg ? (int) $pkg->revision_limit : 0,
            'priority_support' => $pkg ? (bool) $pkg->priority_support : false,
            'included_platforms' => $pkg ? json_decode($pkg->included_platforms ?? '[]', true) : [],
            'days_passed' => $daysPassed,
            'days_remaining' => $daysRemaining,
            'days_in_month' => $daysInMonth,
            'month_progress' => round(($daysPassed / $daysInMonth) * 100),
            'content_projected' => $daysPassed > 0 ? (int) ceil(($usage['content_created'] ?? 0) / $daysPassed * $daysRemaining) : 0,
            'workflow_projected' => $daysPassed > 0 ? (int) ceil(($usage['workflow_items'] ?? 0) / $daysPassed * $daysRemaining) : 0,
        ];

        $this->showClientModal = true;
    }
}
?>

<div class="space-y-6" x-data="{ activeTab: 'all' }">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-gray-900">Packages</h1>
            <p class="text-sm text-gray-500 mt-0.5">Manage subscription packages and track all client usage</p>
        </div>
        <button wire:click="openPkgForm" class="btn btn-primary">
            <i class="fas fa-plus mr-1.5"></i> Add Package
        </button>
    </div>

    {{-- Package Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        @forelse($packages as $pkg)
        @php
            $pkgClients = collect($allClientsUsage)->filter(fn($c) => $c['package_slug'] === $pkg['slug']);
            $avgContentPct = $pkgClients->count() > 0 ? round($pkgClients->avg('content_pct')) : 0;
            $avgWorkflowPct = $pkgClients->count() > 0 ? round($pkgClients->avg('workflow_pct')) : 0;
            $overLimitCount = $pkgClients->filter(fn($c) => $c['is_over_limit'])->count();
        @endphp
        <div wire:click="openDetail({{ $pkg['id'] }})" class="group relative rounded-2xl border border-gray-100 bg-white p-5 hover:shadow-lg hover:border-[var(--brand)]/30 transition-all cursor-pointer">
            <div class="flex items-start justify-between mb-3">
                <span class="badge badge-{{ $pkg['slug'] }}">{{ $pkg['name'] }}</span>
                <span class="badge {{ $pkg['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($pkg['status']) }}</span>
            </div>
            <p class="text-2xl font-extrabold text-gray-900">NPR {{ number_format($pkg['monthly_amount']) }}<span class="text-xs font-normal text-gray-400">/mo</span></p>
            <div class="flex items-center gap-4 mt-3 text-xs text-gray-500">
                <span><i class="fas fa-users mr-1 text-[var(--brand)]"></i>{{ $pkg['client_count'] }}</span>
                <span><i class="fas fa-dollar-sign mr-1 text-green-500"></i>{{ number_format($pkg['total_revenue']) }}</span>
                @if($overLimitCount > 0)
                <span class="text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $overLimitCount }} over</span>
                @endif
            </div>
            <div class="mt-4 space-y-2">
                <div>
                    <div class="flex justify-between text-[11px] mb-0.5">
                        <span class="text-gray-500">Content</span>
                        <span class="font-semibold {{ $avgContentPct >= 90 ? 'text-red-500' : ($avgContentPct >= 70 ? 'text-amber-500' : 'text-green-500') }}">{{ $avgContentPct }}%</span>
                    </div>
                    <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full {{ $avgContentPct >= 90 ? 'bg-red-500' : ($avgContentPct >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $avgContentPct }}%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-[11px] mb-0.5">
                        <span class="text-gray-500">Workflow</span>
                        <span class="font-semibold {{ $avgWorkflowPct >= 90 ? 'text-red-500' : ($avgWorkflowPct >= 70 ? 'text-amber-500' : 'text-green-500') }}">{{ $avgWorkflowPct }}%</span>
                    </div>
                    <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full {{ $avgWorkflowPct >= 90 ? 'bg-red-500' : ($avgWorkflowPct >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $avgWorkflowPct }}%"></div>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5 mt-3">
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600"><i class="fas fa-file-alt mr-0.5"></i>{{ $pkg['content_limit'] }}/mo</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600"><i class="fas fa-columns mr-0.5"></i>{{ $pkg['workflow_limit'] }}/mo</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600"><i class="fas fa-hdd mr-0.5"></i>{{ $pkg['storage_limit_mb'] }}MB</span>
                @if($pkg['priority_support'])
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700"><i class="fas fa-headset mr-0.5"></i>Priority</span>
                @endif
            </div>
            @if(!empty($pkg['included_platforms']))
            <div class="flex flex-wrap gap-1 mt-2">
                @foreach($pkg['included_platforms'] as $platform)
                <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-[var(--brand)]/10 text-[var(--brand)]">{{ ucfirst($platform) }}</span>
                @endforeach
            </div>
            @endif
            <div class="flex gap-2 mt-3 pt-3 border-t border-gray-50 opacity-0 group-hover:opacity-100 transition-opacity">
                <button wire:click.stop="openPkgForm({{ $pkg['id'] }})" class="text-xs text-[var(--brand)] hover:underline"><i class="fas fa-pen mr-1"></i>Edit</button>
                <button wire:click.stop="deletePkg({{ $pkg['id'] }})" wire:confirm="Delete this package?" class="text-xs text-red-500 hover:underline"><i class="fas fa-trash mr-1"></i>Delete</button>
            </div>
        </div>
        @empty
        <div class="col-span-4 text-center py-12">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 flex items-center justify-center mb-4">
                <i class="fas fa-box text-2xl text-gray-400"></i>
            </div>
            <p class="text-gray-500 text-sm">No packages yet</p>
            <button wire:click="openPkgForm" class="btn btn-primary btn-sm mt-3"><i class="fas fa-plus mr-1"></i> Create First Package</button>
        </div>
        @endforelse
    </div>

    {{-- All Clients Package Tracking --}}
    @if(count($allClientsUsage) > 0)
    @php $counts = $this->filterCounts; @endphp
    <div class="bg-white rounded-2xl border border-gray-100 p-5">
        {{-- Header with Filters --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
            <div>
                <h3 class="text-sm font-bold text-gray-900">Client Package Tracking</h3>
                <p class="text-xs text-gray-500 mt-0.5">{{ $counts['all'] }} active client(s) across all packages</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                {{-- Package Filter --}}
                <select wire:model.live="packageFilter" class="form-select text-xs py-1.5 px-3 w-36">
                    <option value="all">All Packages</option>
                    @foreach($packages as $pkg)
                    <option value="{{ $pkg['slug'] }}">{{ $pkg['name'] }} ({{ collect($allClientsUsage)->filter(fn($c) => $c['package_slug'] === $pkg['slug'])->count() }})</option>
                    @endforeach
                </select>

                {{-- Status Filter Tabs --}}
                <div class="flex bg-gray-100 rounded-lg p-0.5">
                    <button wire:click="$set('clientFilter', 'all')" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $clientFilter === 'all' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                        All <span class="ml-1 text-[10px]">{{ $counts['all'] }}</span>
                    </button>
                    <button wire:click="$set('clientFilter', 'over')" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $clientFilter === 'over' ? 'bg-red-500 text-white shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                        <i class="fas fa-circle text-[6px] mr-1 {{ $counts['over'] > 0 ? 'text-red-400' : 'text-gray-400' }}"></i>Over <span class="ml-1 text-[10px]">{{ $counts['over'] }}</span>
                    </button>
                    <button wire:click="$set('clientFilter', 'warning')" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $clientFilter === 'warning' ? 'bg-amber-500 text-white shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                        <i class="fas fa-circle text-[6px] mr-1 {{ $counts['warning'] > 0 ? 'text-amber-400' : 'text-gray-400' }}"></i>Near <span class="ml-1 text-[10px]">{{ $counts['warning'] }}</span>
                    </button>
                    <button wire:click="$set('clientFilter', 'ok')" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $clientFilter === 'ok' ? 'bg-green-500 text-white shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                        <i class="fas fa-circle text-[6px] mr-1 text-green-400"></i>OK <span class="ml-1 text-[10px]">{{ $counts['ok'] }}</span>
                    </button>
                </div>

                {{-- Search --}}
                <div class="relative">
                    <input type="text" wire:model.live.debounce.300ms="clientSearch" placeholder="Search clients..." class="form-input text-xs py-1.5 pl-9 pr-3 w-48">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[10px]"></i>
                </div>

                {{-- Reset Filters --}}
                @if($clientFilter !== 'all' || $packageFilter !== 'all' || $clientSearch)
                <button wire:click="resetFilters" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1.5 rounded-lg hover:bg-gray-100" title="Reset filters">
                    <i class="fas fa-times mr-1"></i>Clear
                </button>
                @endif

                {{-- View Toggle --}}
                <div class="flex bg-gray-100 rounded-lg p-0.5">
                    <button wire:click="$set('viewMode', 'table')" class="px-2 py-1.5 text-xs rounded-md transition-colors {{ $viewMode === 'table' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}" title="Table view">
                        <i class="fas fa-list"></i>
                    </button>
                    <button wire:click="$set('viewMode', 'grid')" class="px-2 py-1.5 text-xs rounded-md transition-colors {{ $viewMode === 'grid' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}" title="Grid view">
                        <i class="fas fa-th-large"></i>
                    </button>
                </div>
            </div>
        </div>

        {{-- Active Filters Display --}}
        @if($clientFilter !== 'all' || $packageFilter !== 'all' || $clientSearch)
        <div class="flex items-center gap-2 mb-3 flex-wrap">
            <span class="text-[10px] text-gray-400 uppercase font-bold">Active filters:</span>
            @if($packageFilter !== 'all')
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-[var(--brand)]/10 text-[var(--brand)] font-semibold">
                Package: {{ ucfirst($packageFilter) }}
                <button wire:click="$set('packageFilter', 'all')" class="ml-1 hover:text-[var(--brand)]/70"><i class="fas fa-times"></i></button>
            </span>
            @endif
            @if($clientFilter !== 'all')
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-{{ $clientFilter === 'over' ? 'red' : ($clientFilter === 'warning' ? 'amber' : 'green') }}-100 text-{{ $clientFilter === 'over' ? 'red' : ($clientFilter === 'warning' ? 'amber' : 'green') }}-700 font-semibold">
                Status: {{ ucfirst($clientFilter) }}
                <button wire:click="$set('clientFilter', 'all')" class="ml-1"><i class="fas fa-times"></i></button>
            </span>
            @endif
            @if($clientSearch)
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 font-semibold">
                Search: "{{ $clientSearch }}"
                <button wire:click="$set('clientSearch', '')" class="ml-1"><i class="fas fa-times"></i></button>
            </span>
            @endif
        </div>
        @endif

        {{-- Month Progress --}}
        @php
            $now = \Carbon\Carbon::now();
            $daysInMonth = $now->daysInMonth;
            $daysPassed = $now->day;
            $daysRemaining = $daysInMonth - $daysPassed;
            $monthProgress = round(($daysPassed / $daysInMonth) * 100);
        @endphp
        <div class="bg-blue-50 rounded-xl p-3 mb-4 flex items-center gap-4 text-xs text-blue-700">
            <i class="fas fa-calendar-day text-blue-500"></i>
            <span class="font-semibold">{{ $now->format('F Y') }}</span>
            <span>{{ $daysPassed }}/{{ $daysInMonth }} days passed</span>
            <span>{{ $daysRemaining }} days remaining</span>
            <div class="flex-1 h-1.5 bg-blue-200 rounded-full overflow-hidden">
                <div class="h-full bg-blue-500 rounded-full" style="width: {{ $monthProgress }}%"></div>
            </div>
            <span class="font-bold">{{ $monthProgress }}%</span>
        </div>

        {{-- Clients Table --}}
        <div class="overflow-x-auto">
            <table class="data-table w-full text-xs">
                <thead>
                    <tr>
                        <th class="text-left cursor-pointer hover:text-gray-900" wire:click="toggleSort('client_name')">
                            <div class="flex items-center gap-1">
                                Client
                                @if($sortBy === 'client_name')
                                <i class="fas fa-sort-{{ $sortDesc ? 'down' : 'up' }} text-[var(--brand)]"></i>
                                @else
                                <i class="fas fa-sort text-gray-300"></i>
                                @endif
                            </div>
                        </th>
                        <th class="text-left cursor-pointer hover:text-gray-900" wire:click="toggleSort('package_name')">
                            <div class="flex items-center gap-1">
                                Package
                                @if($sortBy === 'package_name')
                                <i class="fas fa-sort-{{ $sortDesc ? 'down' : 'up' }} text-[var(--brand)]"></i>
                                @else
                                <i class="fas fa-sort text-gray-300"></i>
                                @endif
                            </div>
                        </th>
                        <th class="text-center cursor-pointer hover:text-gray-900" wire:click="toggleSort('content_pct')">
                            <div class="flex items-center justify-center gap-1">
                                Content
                                @if($sortBy === 'content_pct')
                                <i class="fas fa-sort-{{ $sortDesc ? 'down' : 'up' }} text-[var(--brand)]"></i>
                                @else
                                <i class="fas fa-sort text-gray-300"></i>
                                @endif
                            </div>
                        </th>
                        <th class="text-center cursor-pointer hover:text-gray-900" wire:click="toggleSort('workflow_pct')">
                            <div class="flex items-center justify-center gap-1">
                                Workflow
                                @if($sortBy === 'workflow_pct')
                                <i class="fas fa-sort-{{ $sortDesc ? 'down' : 'up' }} text-[var(--brand)]"></i>
                                @else
                                <i class="fas fa-sort text-gray-300"></i>
                                @endif
                            </div>
                        </th>
                        <th class="text-center cursor-pointer hover:text-gray-900" wire:click="toggleSort('storage_pct')">
                            <div class="flex items-center justify-center gap-1">
                                Storage
                                @if($sortBy === 'storage_pct')
                                <i class="fas fa-sort-{{ $sortDesc ? 'down' : 'up' }} text-[var(--brand)]"></i>
                                @else
                                <i class="fas fa-sort text-gray-300"></i>
                                @endif
                            </div>
                        </th>
                        <th class="text-center">Approvals</th>
                        <th class="text-center">Files</th>
                        <th class="text-center">Status</th>
                        <th class="text-center w-20">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->filteredClients as $client)
                    <tr class="{{ $client['is_over_limit'] ? 'bg-red-50/50' : ($client['needs_attention'] ? 'bg-amber-50/50' : 'hover:bg-gray-50') }} transition-colors cursor-pointer" wire:click="openClientDetail({{ $client['client_id'] }})">
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-full bg-[var(--brand)]/10 flex items-center justify-center text-[var(--brand)] text-xs font-bold flex-shrink-0">
                                    {{ strtoupper(substr($client['client_name'], 0, 2)) }}
                                </div>
                                <span class="font-semibold text-gray-900">{{ $client['client_name'] }}</span>
                            </div>
                        </td>
                        <td><span class="badge badge-{{ $client['package_slug'] }}">{{ $client['package_name'] }}</span></td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <div class="w-14 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full {{ $client['content_pct'] >= 90 ? 'bg-red-500' : ($client['content_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['content_pct'] }}%"></div>
                                </div>
                                <span class="font-semibold w-12 text-right {{ $client['content_pct'] >= 90 ? 'text-red-600' : ($client['content_pct'] >= 70 ? 'text-amber-600' : 'text-gray-600') }}">{{ $client['content_used'] }}/{{ $client['content_limit'] }}</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <div class="w-14 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full {{ $client['workflow_pct'] >= 90 ? 'bg-red-500' : ($client['workflow_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['workflow_pct'] }}%"></div>
                                </div>
                                <span class="font-semibold w-12 text-right {{ $client['workflow_pct'] >= 90 ? 'text-red-600' : ($client['workflow_pct'] >= 70 ? 'text-amber-600' : 'text-gray-600') }}">{{ $client['workflow_used'] }}/{{ $client['workflow_limit'] }}</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <div class="w-14 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full {{ $client['storage_pct'] >= 90 ? 'bg-red-500' : ($client['storage_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['storage_pct'] }}%"></div>
                                </div>
                                <span class="font-semibold w-16 text-right {{ $client['storage_pct'] >= 90 ? 'text-red-600' : ($client['storage_pct'] >= 70 ? 'text-amber-600' : 'text-gray-600') }}">{{ $client['storage_used'] }}/{{ $client['storage_limit'] }}MB</span>
                            </div>
                        </td>
                        <td class="text-center font-semibold text-gray-600">{{ $client['approvals_used'] }}</td>
                        <td class="text-center font-semibold text-gray-600">{{ $client['files_uploaded'] }}</td>
                        <td class="text-center">
                            @if($client['is_over_limit'])
                            <span class="badge bg-red-100 text-red-700">Over Limit</span>
                            @elseif($client['needs_attention'])
                            <span class="badge bg-amber-100 text-amber-700">Near Limit</span>
                            @else
                            <span class="badge bg-green-100 text-green-700">On Track</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <button wire:click.stop="openClientDetail({{ $client['client_id'] }})" class="text-[var(--brand)] hover:text-[var(--brand)]/80 text-xs" title="View Details">
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center py-8 text-gray-400">
                            <i class="fas fa-search text-2xl mb-2 block"></i>
                            <p class="text-sm">No clients found matching your criteria</p>
                            <button wire:click="resetFilters" class="text-xs text-[var(--brand)] mt-2 hover:underline">Clear all filters</button>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Grid View --}}
        @if($viewMode === 'grid')
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
            @forelse($this->filteredClients as $client)
            <div wire:click="openClientDetail({{ $client['client_id'] }})" class="group rounded-xl border border-gray-100 p-4 hover:shadow-md hover:border-[var(--brand)]/30 transition-all cursor-pointer {{ $client['is_over_limit'] ? 'border-red-200 bg-red-50/30' : ($client['needs_attention'] ? 'border-amber-200 bg-amber-50/30' : '') }}">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-full bg-[var(--brand)]/10 flex items-center justify-center text-[var(--brand)] text-sm font-bold">
                        {{ strtoupper(substr($client['client_name'], 0, 2)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-gray-900 text-sm truncate">{{ $client['client_name'] }}</p>
                        <span class="badge badge-{{ $client['package_slug'] }} text-[10px]">{{ $client['package_name'] }}</span>
                    </div>
                    @if($client['is_over_limit'])
                    <span class="badge bg-red-100 text-red-700 text-[10px]">Over</span>
                    @elseif($client['needs_attention'])
                    <span class="badge bg-amber-100 text-amber-700 text-[10px]">Near</span>
                    @endif
                </div>
                <div class="space-y-2">
                    <div>
                        <div class="flex justify-between text-[11px] mb-0.5">
                            <span class="text-gray-500">Content</span>
                            <span class="font-semibold {{ $client['content_pct'] >= 90 ? 'text-red-500' : ($client['content_pct'] >= 70 ? 'text-amber-500' : 'text-gray-600') }}">{{ $client['content_used'] }}/{{ $client['content_limit'] }}</span>
                        </div>
                        <div class="h-1.5 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full rounded-full {{ $client['content_pct'] >= 90 ? 'bg-red-500' : ($client['content_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['content_pct'] }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-[11px] mb-0.5">
                            <span class="text-gray-500">Workflow</span>
                            <span class="font-semibold {{ $client['workflow_pct'] >= 90 ? 'text-red-500' : ($client['workflow_pct'] >= 70 ? 'text-amber-500' : 'text-gray-600') }}">{{ $client['workflow_used'] }}/{{ $client['workflow_limit'] }}</span>
                        </div>
                        <div class="h-1.5 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full rounded-full {{ $client['workflow_pct'] >= 90 ? 'bg-red-500' : ($client['workflow_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['workflow_pct'] }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-[11px] mb-0.5">
                            <span class="text-gray-500">Storage</span>
                            <span class="font-semibold {{ $client['storage_pct'] >= 90 ? 'text-red-500' : ($client['storage_pct'] >= 70 ? 'text-amber-500' : 'text-gray-600') }}">{{ $client['storage_used'] }}/{{ $client['storage_limit'] }}MB</span>
                        </div>
                        <div class="h-1.5 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full rounded-full {{ $client['storage_pct'] >= 90 ? 'bg-red-500' : ($client['storage_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['storage_pct'] }}%"></div>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100 text-[10px] text-gray-500">
                    <span><i class="fas fa-check-double mr-1 text-green-500"></i>{{ $client['approvals_used'] }} approvals</span>
                    <span><i class="fas fa-file-upload mr-1 text-orange-500"></i>{{ $client['files_uploaded'] }} files</span>
                </div>
            </div>
            @empty
            <div class="col-span-3 text-center py-8 text-gray-400">
                <i class="fas fa-search text-2xl mb-2 block"></i>
                <p class="text-sm">No clients found matching your criteria</p>
                <button wire:click="resetFilters" class="text-xs text-[var(--brand)] mt-2 hover:underline">Clear all filters</button>
            </div>
            @endforelse
        </div>
        @endif

        {{-- Summary Footer --}}
        @if(count($this->filteredClients) > 0)
        @php $filtered = $this->filteredClients; @endphp
        <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-100 text-xs text-gray-500">
            <div class="flex items-center gap-4">
                <span>Showing <strong class="text-gray-700">{{ count($filtered) }}</strong> of <strong class="text-gray-700">{{ $counts['all'] }}</strong> client(s)</span>
                @if($packageFilter !== 'all')
                <span class="text-[var(--brand)]">Filtered by: <strong>{{ ucfirst($packageFilter) }}</strong></span>
                @endif
            </div>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-1">
                    <span class="text-gray-400">Avg:</span>
                    <span class="text-gray-600">Content <strong>{{ count($filtered) > 0 ? round(array_sum(array_column($filtered, 'content_pct')) / count($filtered)) : 0 }}%</strong></span>
                    <span class="text-gray-300">·</span>
                    <span class="text-gray-600">Workflow <strong>{{ count($filtered) > 0 ? round(array_sum(array_column($filtered, 'workflow_pct')) / count($filtered)) : 0 }}%</strong></span>
                    <span class="text-gray-300">·</span>
                    <span class="text-gray-600">Storage <strong>{{ count($filtered) > 0 ? round(array_sum(array_column($filtered, 'storage_pct')) / count($filtered)) : 0 }}%</strong></span>
                </div>
            </div>
        </div>
        @endif
    </div>
    @endif

    {{-- Package Form Modal --}}
    @if($showPkgForm)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-transition>
        <div class="fixed inset-0 bg-black/50" wire:click="$set('showPkgForm', false)"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto" x-transition>
            <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between z-10">
                <h3 class="text-base font-bold text-gray-900">{{ $editingPkgId ? 'Edit Package' : 'New Package' }}</h3>
                <button wire:click="$set('showPkgForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Name *</label>
                        <input type="text" wire:model="pkgName" class="form-input w-full text-sm" placeholder="e.g. Premium">
                        @error('pkgName') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Slug *</label>
                        <input type="text" wire:model="pkgSlug" class="form-input w-full text-sm" placeholder="e.g. premium">
                        @error('pkgSlug') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Monthly Price (NPR) *</label>
                        <input type="number" wire:model="pkgAmount" class="form-input w-full text-sm" min="0">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Status</label>
                        <select wire:model="pkgStatus" class="form-select w-full text-sm">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Deliverables</label>
                    <input type="text" wire:model="pkgDeliverables" class="form-input w-full text-sm" placeholder="e.g. 8 reels + 4 posts per month">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Features (one per line)</label>
                    <textarea wire:model="pkgFeatures" class="form-textarea w-full text-sm" rows="3" placeholder="8 reels per month&#10;Analytics dashboard&#10;Priority support"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Included Platforms (comma-separated)</label>
                    <input type="text" wire:model="pkgPlatforms" class="form-input w-full text-sm" placeholder="instagram, facebook, tiktok">
                </div>
                <div class="border-t border-gray-100 pt-4">
                    <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-3">Monthly Limits</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Content Items</label>
                            <input type="number" wire:model="pkgContentLimit" class="form-input w-full text-sm" min="0">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Workflow Items</label>
                            <input type="number" wire:model="pkgWorkflowLimit" class="form-input w-full text-sm" min="0">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Storage (MB)</label>
                            <input type="number" wire:model="pkgStorageLimit" class="form-input w-full text-sm" min="0">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Revisions</label>
                            <input type="number" wire:model="pkgRevisionLimit" class="form-input w-full text-sm" min="0">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="pkgPrioritySupport" class="rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]">
                            <span class="text-xs font-semibold text-gray-600">Priority Support</span>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
                    <button wire:click="$set('showPkgForm', false)" class="btn btn-secondary btn-sm">Cancel</button>
                    <button wire:click="savePkg" class="btn btn-primary btn-sm"><i class="fas fa-save mr-1"></i> {{ $editingPkgId ? 'Update' : 'Create' }}</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Package Detail Modal --}}
    @if($showDetailModal)
    @php $summary = $this->detailSummary; @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-transition>
        <div class="fixed inset-0 bg-black/50" wire:click="$set('showDetailModal', false)"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto" x-transition>
            <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between z-10">
                <div class="flex items-center gap-3">
                    <span class="badge badge-{{ $detailPackage['slug'] ?? '' }}">{{ $detailPackage['name'] ?? '' }}</span>
                    <span class="text-sm font-semibold text-gray-900">NPR {{ number_format($detailPackage['monthly_amount'] ?? 0) }}/mo</span>
                </div>
                <button wire:click="$set('showDetailModal', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="p-6 space-y-6">
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-2xl font-extrabold text-gray-900">{{ $summary['client_count'] }}</p>
                        <p class="text-[10px] text-gray-500 mt-0.5">Clients</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-2xl font-extrabold text-green-600">NPR {{ number_format($summary['total_revenue']) }}</p>
                        <p class="text-[10px] text-gray-500 mt-0.5">Monthly Revenue</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-2xl font-extrabold {{ $summary['avg_content_pct'] >= 80 ? 'text-amber-600' : 'text-gray-900' }}">{{ $summary['avg_content_pct'] }}%</p>
                        <p class="text-[10px] text-gray-500 mt-0.5">Avg Content Used</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-2xl font-extrabold {{ $summary['avg_workflow_pct'] >= 80 ? 'text-amber-600' : 'text-gray-900' }}">{{ $summary['avg_workflow_pct'] }}%</p>
                        <p class="text-[10px] text-gray-500 mt-0.5">Avg Workflow Used</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-2xl font-extrabold {{ $summary['total_over_limit'] > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $summary['total_over_limit'] }}</p>
                        <p class="text-[10px] text-gray-500 mt-0.5">Over Limit</p>
                    </div>
                </div>

                @if(!empty($detailPackage['features']))
                <div>
                    <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Included Features</h4>
                    <div class="flex flex-wrap gap-2">
                        @foreach($detailPackage['features'] as $feature)
                        <span class="text-xs px-2.5 py-1 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] font-medium">{{ $feature }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                @php
                    $now = \Carbon\Carbon::now();
                    $daysInMonth = $now->daysInMonth;
                    $daysPassed = $now->day;
                    $daysRemaining = $daysInMonth - $daysPassed;
                    $monthProgress = round(($daysPassed / $daysInMonth) * 100);
                @endphp
                <div class="bg-blue-50 rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-clock text-blue-500"></i>
                        <h4 class="text-xs font-bold text-blue-900">Current Month: {{ $now->format('F Y') }}</h4>
                    </div>
                    <div class="flex items-center gap-4 text-xs text-blue-700">
                        <span><i class="fas fa-calendar-day mr-1"></i>{{ $daysPassed }}/{{ $daysInMonth }} days passed</span>
                        <span><i class="fas fa-hourglass-half mr-1"></i>{{ $daysRemaining }} days remaining</span>
                        <span><i class="fas fa-percentage mr-1"></i>{{ $monthProgress }}% of month</span>
                    </div>
                    <div class="mt-2 h-2 bg-blue-200 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-500 rounded-full" style="width: {{ $monthProgress }}%"></div>
                    </div>
                </div>

                <div>
                    <div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="text" wire:model.live.debounce.300ms="detailSearch" placeholder="Search clients..." class="form-input pl-10 w-full text-sm" /></div>
                </div>

                <div class="overflow-x-auto">
                    <table class="data-table w-full text-xs">
                        <thead>
                            <tr>
                                <th class="text-left">Client</th>
                                <th class="text-center">Content</th>
                                <th class="text-center">Workflow</th>
                                <th class="text-center">Storage</th>
                                <th class="text-center">Approvals</th>
                                <th class="text-center">Days Left</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($this->filteredDetailClients as $client)
                            <tr class="hover:bg-gray-50 cursor-pointer" wire:click="openClientDetail({{ $client['client_id'] }})">
                                <td>
                                    <div>
                                        <p class="font-semibold text-gray-900">{{ $client['client_name'] }}</p>
                                        <p class="text-[10px] text-gray-400">NPR {{ number_format($client['amount']) }}/mo</p>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div>
                                        <div class="flex items-center justify-center gap-1">
                                            <div class="w-12 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                                <div class="h-full rounded-full {{ $client['content_status'] === 'over' ? 'bg-red-500' : ($client['content_status'] === 'warning' ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['content_pct'] }}%"></div>
                                            </div>
                                            <span class="font-semibold {{ $client['content_status'] === 'over' ? 'text-red-600' : ($client['content_status'] === 'warning' ? 'text-amber-600' : 'text-gray-600') }}">{{ $client['content_used'] }}/{{ $client['content_limit'] }}</span>
                                        </div>
                                        <p class="text-[9px] text-gray-400 mt-0.5">Projected: {{ $client['content_projected'] }}</p>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div>
                                        <div class="flex items-center justify-center gap-1">
                                            <div class="w-12 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                                <div class="h-full rounded-full {{ $client['workflow_status'] === 'over' ? 'bg-red-500' : ($client['workflow_status'] === 'warning' ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['workflow_pct'] }}%"></div>
                                            </div>
                                            <span class="font-semibold {{ $client['workflow_status'] === 'over' ? 'text-red-600' : ($client['workflow_status'] === 'warning' ? 'text-amber-600' : 'text-gray-600') }}">{{ $client['workflow_used'] }}/{{ $client['workflow_limit'] }}</span>
                                        </div>
                                        <p class="text-[9px] text-gray-400 mt-0.5">Projected: {{ $client['workflow_projected'] }}</p>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <div class="w-12 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full rounded-full {{ $client['storage_status'] === 'over' ? 'bg-red-500' : ($client['storage_status'] === 'warning' ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $client['storage_pct'] }}%"></div>
                                        </div>
                                        <span class="font-semibold {{ $client['storage_status'] === 'over' ? 'text-red-600' : ($client['storage_status'] === 'warning' ? 'text-amber-600' : 'text-gray-600') }}">{{ $client['storage_used'] }}/{{ $client['storage_limit'] }}MB</span>
                                    </div>
                                </td>
                                <td class="text-center font-semibold">{{ $client['approvals_used'] }}</td>
                                <td class="text-center">
                                    @if($client['contract_days_left'] !== null)
                                        @if($client['contract_days_left'] <= 30)
                                        <span class="text-red-600 font-semibold">{{ $client['contract_days_left'] }}d</span>
                                        @else
                                        <span class="text-gray-600">{{ $client['contract_days_left'] }}d</span>
                                        @endif
                                    @else
                                    <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($client['content_status'] === 'over' || $client['workflow_status'] === 'over' || $client['storage_status'] === 'over')
                                    <span class="badge bg-red-100 text-red-700">Over Limit</span>
                                    @elseif($client['content_status'] === 'warning' || $client['workflow_status'] === 'warning' || $client['storage_status'] === 'warning')
                                    <span class="badge bg-amber-100 text-amber-700">Near Limit</span>
                                    @else
                                    <span class="badge bg-green-100 text-green-700">On Track</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center py-6 text-gray-400">No clients found</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Client Detail Modal --}}
    @if($showClientModal && !empty($clientDetail))
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-transition>
        <div class="fixed inset-0 bg-black/50" wire:click="$set('showClientModal', false)"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto" x-transition>
            <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between z-10">
                <div>
                    <h3 class="text-base font-bold text-gray-900">{{ $clientDetail['name'] }}</h3>
                    <p class="text-xs text-gray-500">{{ $clientDetail['email'] }} {{ $clientDetail['phone'] ? '· ' . $clientDetail['phone'] : '' }}</p>
                </div>
                <button wire:click="$set('showClientModal', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>

            <div class="p-6 space-y-5">
                {{-- Package & Contract --}}
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gray-50 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="fas fa-box text-[var(--brand)]"></i>
                            <span class="text-xs font-bold text-gray-700">Package</span>
                        </div>
                        <span class="badge badge-{{ $clientDetail['package_slug'] }}">{{ $clientDetail['package_name'] }}</span>
                        <p class="text-lg font-extrabold text-gray-900 mt-1">NPR {{ number_format($clientDetail['amount']) }}<span class="text-xs font-normal text-gray-400">/mo</span></p>
                        @if($clientDetail['priority_support'])
                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 mt-1 inline-block"><i class="fas fa-headset mr-0.5"></i>Priority Support</span>
                        @endif
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="fas fa-file-contract text-blue-500"></i>
                            <span class="text-xs font-bold text-gray-700">Contract</span>
                        </div>
                        <div class="text-xs text-gray-600 space-y-1">
                            <p>Start: <strong>{{ $clientDetail['contract_start'] }}</strong></p>
                            <p>End: <strong>{{ $clientDetail['contract_end'] }}</strong></p>
                            @if($clientDetail['contract_days_left'] !== null)
                                @if($clientDetail['contract_days_left'] <= 30)
                                <p class="text-red-600 font-bold"><i class="fas fa-exclamation-triangle mr-1"></i>{{ $clientDetail['contract_days_left'] }} days remaining!</p>
                                @else
                                <p>{{ $clientDetail['contract_days_left'] }} days remaining</p>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Month Progress --}}
                <div class="bg-blue-50 rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-clock text-blue-500"></i>
                        <h4 class="text-xs font-bold text-blue-900">{{ now()->format('F Y') }} — {{ $clientDetail['days_passed'] }}/{{ $clientDetail['days_in_month'] }} days ({{ $clientDetail['month_progress'] }}%)</h4>
                    </div>
                    <div class="h-2 bg-blue-200 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-500 rounded-full" style="width: {{ $clientDetail['month_progress'] }}%"></div>
                    </div>
                    <p class="text-[10px] text-blue-600 mt-1">{{ $clientDetail['days_remaining'] }} days remaining this month</p>
                </div>

                {{-- Usage Meters --}}
                <div class="grid grid-cols-2 gap-4">
                    {{-- Content --}}
                    <div class="rounded-xl border border-gray-100 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-700"><i class="fas fa-file-alt mr-1 text-[var(--brand)]"></i>Content</span>
                            <span class="text-sm font-extrabold {{ $clientDetail['content_pct'] >= 90 ? 'text-red-600' : ($clientDetail['content_pct'] >= 70 ? 'text-amber-600' : 'text-gray-900') }}">{{ $clientDetail['content_pct'] }}%</span>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-900">{{ $clientDetail['content_used'] }}<span class="text-sm font-normal text-gray-400">/{{ $clientDetail['content_limit'] }}</span></p>
                        <div class="mt-2 h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full rounded-full {{ $clientDetail['content_pct'] >= 90 ? 'bg-red-500' : ($clientDetail['content_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $clientDetail['content_pct'] }}%"></div>
                        </div>
                        <div class="flex justify-between mt-2 text-[10px] text-gray-500">
                            <span>{{ $clientDetail['content_limit'] - $clientDetail['content_used'] }} remaining</span>
                            <span>Projected: {{ $clientDetail['content_projected'] }}</span>
                        </div>
                        <p class="text-[10px] text-gray-400 mt-1">{{ $clientDetail['content_published'] }} published</p>
                    </div>

                    {{-- Workflow --}}
                    <div class="rounded-xl border border-gray-100 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-700"><i class="fas fa-columns mr-1 text-purple-500"></i>Workflow</span>
                            <span class="text-sm font-extrabold {{ $clientDetail['workflow_pct'] >= 90 ? 'text-red-600' : ($clientDetail['workflow_pct'] >= 70 ? 'text-amber-600' : 'text-gray-900') }}">{{ $clientDetail['workflow_pct'] }}%</span>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-900">{{ $clientDetail['workflow_used'] }}<span class="text-sm font-normal text-gray-400">/{{ $clientDetail['workflow_limit'] }}</span></p>
                        <div class="mt-2 h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full rounded-full {{ $clientDetail['workflow_pct'] >= 90 ? 'bg-red-500' : ($clientDetail['workflow_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $clientDetail['workflow_pct'] }}%"></div>
                        </div>
                        <div class="flex justify-between mt-2 text-[10px] text-gray-500">
                            <span>{{ $clientDetail['workflow_limit'] - $clientDetail['workflow_used'] }} remaining</span>
                            <span>Projected: {{ $clientDetail['workflow_projected'] }}</span>
                        </div>
                    </div>

                    {{-- Storage --}}
                    <div class="rounded-xl border border-gray-100 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-700"><i class="fas fa-hdd mr-1 text-blue-500"></i>Storage</span>
                            <span class="text-sm font-extrabold {{ $clientDetail['storage_pct'] >= 90 ? 'text-red-600' : ($clientDetail['storage_pct'] >= 70 ? 'text-amber-600' : 'text-gray-900') }}">{{ $clientDetail['storage_pct'] }}%</span>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-900">{{ $clientDetail['storage_used'] }}<span class="text-sm font-normal text-gray-400">/{{ $clientDetail['storage_limit'] }}MB</span></p>
                        <div class="mt-2 h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full rounded-full {{ $clientDetail['storage_pct'] >= 90 ? 'bg-red-500' : ($clientDetail['storage_pct'] >= 70 ? 'bg-amber-500' : 'bg-green-500') }}" style="width: {{ $clientDetail['storage_pct'] }}%"></div>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-2">{{ $clientDetail['storage_limit'] - $clientDetail['storage_used'] }}MB remaining</p>
                    </div>

                    {{-- Other Stats --}}
                    <div class="rounded-xl border border-gray-100 p-4">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-600"><i class="fas fa-check-double mr-1 text-green-500"></i>Approvals Used</span>
                                <span class="text-sm font-extrabold text-gray-900">{{ $clientDetail['approvals_used'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-600"><i class="fas fa-file-upload mr-1 text-orange-500"></i>Files Uploaded</span>
                                <span class="text-sm font-extrabold text-gray-900">{{ $clientDetail['files_uploaded'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-600"><i class="fas fa-redo mr-1 text-gray-400"></i>Revisions Limit</span>
                                <span class="text-sm font-extrabold text-gray-900">{{ $clientDetail['revision_limit'] }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Platforms --}}
                @if(!empty($clientDetail['included_platforms']))
                <div>
                    <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Included Platforms</h4>
                    <div class="flex flex-wrap gap-2">
                        @foreach($clientDetail['included_platforms'] as $platform)
                        <span class="text-xs px-2.5 py-1 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] font-medium">{{ ucfirst($platform) }}</span>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
