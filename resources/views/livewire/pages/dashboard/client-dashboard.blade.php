<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Workflow;
use App\Models\Approval;
use App\Models\Content;
use App\Models\WorkingHour;
use App\Models\WorkflowStage;
use App\Services\PackageService;
use App\Services\NotificationService;

new #[Layout('components.layouts.app')] class extends Component {
    private mixed $cachedClient = null;
    private \Illuminate\Support\Collection $cachedStages;
    public array $packageLimits = [];
    public array $packageUsage = [];
    public array $packageAlerts = [];
    public array $upgradeOptions = [];
    public bool $showUpgradeModal = false;
    public string $selectedUpgrade = '';

    public function mount(): void
    {
        $this->cachedStages = \App\Models\WorkflowStage::all()->keyBy('key');
        $this->loadPackageData();
    }

    private function loadPackageData(): void
    {
        $client = $this->getClient();
        if (!$client) return;

        $data = PackageService::getUsageWithStatus($client->id);
        $this->packageLimits = $data['limits'];
        $this->packageUsage = $data['usage'];
        $this->packageAlerts = $data['alerts'] ?? [];
    }

    public function openUpgradeModal(): void
    {
        $client = $this->getClient();
        if (!$client) return;

        $this->upgradeOptions = PackageService::getUpgradeOptions($client->id);
        $this->selectedUpgrade = '';
        $this->showUpgradeModal = true;
    }

    public function selectUpgrade(string $slug): void
    {
        $this->selectedUpgrade = $slug;
    }

    public function confirmUpgrade(): void
    {
        if (empty($this->selectedUpgrade)) {
            $this->dispatch('toast', message: 'Please select a package', type: 'error');
            return;
        }

        $client = $this->getClient();
        if (!$client) return;

        $oldPkg = DB::table('packages')->where('slug', $client->package)->first();
        $oldPackageName = $oldPkg?->name ?? 'None';
        $success = PackageService::upgradePackage($client->id, $this->selectedUpgrade);

        if ($success) {
            $newPackageName = DB::table('packages')->where('slug', $this->selectedUpgrade)->value('name') ?? 'Unknown';

            // Notify admin/staff about the upgrade
            app(NotificationService::class)->sendNotification(
                text: "Client '{$client->name}' upgraded from {$oldPackageName} to {$newPackageName}",
                type: 'success',
                link: route('clients', absolute: false),
                forRole: 'admin',
            );

            $this->showUpgradeModal = false;
            $this->loadPackageData();
            $this->dispatch('toast', message: 'Package upgraded successfully!', type: 'success');
        } else {
            $this->dispatch('toast', message: 'Failed to upgrade package', type: 'error');
        }
    }

    public function getClient()
    {
        if ($this->cachedClient !== null) {
            return $this->cachedClient;
        }
        $user = Auth::guard('client')->user();
        $this->cachedClient = $user?->client;
        return $this->cachedClient;
    }

    public function getActiveProjectsProperty(): int
    {
        $client = $this->getClient();
        if (! $client) return 0;
        return Workflow::where('client_id', $client->id)->where('stage', '!=', 'published')->count();
    }

    public function getPendingApprovalsProperty(): int
    {
        $client = $this->getClient();
        if (! $client) return 0;
        return Approval::where('client_id', $client->id)->where('status', 'pending')->count();
    }

    public function getPublishedContentProperty(): int
    {
        $client = $this->getClient();
        if (! $client) return 0;
        return Content::where('client_id', $client->id)->where('status', 'published')->count();
    }

    public function getTotalContentProperty(): int
    {
        $client = $this->getClient();
        if (! $client) return 0;
        return Content::where('client_id', $client->id)->count();
    }

    public function getWorkingHoursProperty(): \Illuminate\Support\Collection
    {
        return WorkingHour::orderByRaw("FIELD(day, 'mon','tue','wed','thu','fri','sat','sun')")->get();
    }

    public function getMyProjectsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        $client = $this->getClient();
        if (! $client) return collect();
        return Workflow::where('client_id', $client->id)->with('stageInfo')->latest()->take(5)->get();
    }

    public function getPendingApprovalsListProperty(): \Illuminate\Database\Eloquent\Collection
    {
        $client = $this->getClient();
        if (! $client) return collect();
        return Approval::where('client_id', $client->id)->where('status', 'pending')->latest()->take(5)->get();
    }

    public function getStageColor(string $key): string
    {
        return $this->cachedStages->get($key)?->color ?? '#64748b';
    }

    public function getStageName(string $key): string
    {
        return $this->cachedStages->get($key)?->name ?? ucfirst($key);
    }

    public function formatTime(string $time): string
    {
        return \Carbon\Carbon::parse($time)->format('g:i A');
    }

    public function getDayName(string $day): string
    {
        return match ($day) {
            'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
            'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
            default => $day,
        };
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-extrabold text-gray-900">Welcome back, {{ $this->getClient()?->name ?? 'Client' }}</h1>
        <p class="text-sm text-gray-500 mt-1">Here's an overview of your projects and activity.</p>
    </div>

    {{-- Stat Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="stat-card">
            <div class="flex items-center gap-4">
                <div class="stat-icon bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]">
                    <i class="fas fa-project-diagram"></i>
                </div>
                <div>
                    <p class="stat-label">Active Projects</p>
                    <p class="stat-value">{{ $this->activeProjects }}</p>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="flex items-center gap-4">
                <div class="stat-icon bg-amber-50 text-amber-600">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <p class="stat-label">Pending Approvals</p>
                    <p class="stat-value">{{ $this->pendingApprovals }}</p>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="flex items-center gap-4">
                <div class="stat-icon bg-green-50 text-green-600">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <p class="stat-label">Published Content</p>
                    <p class="stat-value">{{ $this->publishedContent }}</p>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="flex items-center gap-4">
                <div class="stat-icon bg-purple-50 text-purple-600">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div>
                    <p class="stat-label">Total Content</p>
                    <p class="stat-value">{{ $this->totalContent }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Package Alerts --}}
    @if (!empty($packageAlerts))
        <div class="space-y-2 mb-6">
            @foreach ($packageAlerts as $alert)
                <div
                    class="flex items-center gap-3 rounded-xl border px-4 py-3 text-sm {{ $alert['type'] === 'danger' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}"
                >
                    <i
                        class="fas {{ $alert['type'] === 'danger' ? 'fa-exclamation-circle text-red-500' : 'fa-exclamation-triangle text-amber-500' }}"
                    ></i>
                    <span class="flex-1">{{ $alert['message'] }}</span>
                    <button wire:click="openUpgradeModal" class="text-xs font-semibold underline hover:no-underline">
                        Upgrade Now
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- My Package Usage --}}
    @if (!empty($packageLimits))
        <div class="bg-gradient-to-r from-[var(--brand)] to-[var(--brand)]/80 rounded-2xl p-6 mb-6 text-white">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span
                            class="badge bg-white/20 text-white"
                            >{{ $packageLimits['package_name'] ?? 'Basic' }}</span
                        >
                        @if ($packageLimits['priority_support'] ?? false)
                            <span class="badge bg-amber-400/20 text-amber-200"
                                ><i class="fas fa-headset mr-1"></i>Priority Support</span
                            >
                        @endif
                    </div>
                    <h3 class="text-lg font-bold mt-2">
                        Your Package: {{ $packageLimits['package_name'] ?? 'Basic' }}
                    </h3>
                    <p class="text-sm text-white/70">NPR {{ number_format($packageLimits['monthly_amount'] ?? 0) }}/month</p>
                </div>
                <button
                    wire:click="openUpgradeModal"
                    class="bg-white text-[var(--brand)] px-4 py-2 rounded-xl font-semibold text-sm hover:bg-white/90 transition-colors"
                >
                    <i class="fas fa-arrow-up mr-1"></i> Upgrade Package
                </button>
            </div>

            @php
            $contentUsed = $packageUsage['content_created'] ?? 0;
            $contentLimit = $packageLimits['content_limit'] ?? 30;
            $contentPct = $contentLimit > 0 ? min(100, round(($contentUsed / $contentLimit) * 100)) : 0;

            $workflowUsed = $packageUsage['workflow_items'] ?? 0;
            $workflowLimit = $packageLimits['workflow_limit'] ?? 20;
            $workflowPct = $workflowLimit > 0 ? min(100, round(($workflowUsed / $workflowLimit) * 100)) : 0;

            $storageUsed = round(($packageUsage['storage_used_bytes'] ?? 0) / 1048576, 1);
            $storageLimit = $packageLimits['storage_limit_mb'] ?? 1024;
            $storagePct = $storageLimit > 0 ? min(100, round(($storageUsed / $storageLimit) * 100)) : 0;

            $approvalUsed = $packageUsage['approvals_used'] ?? 0;
        @endphp

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {{-- Content --}}
                <div class="bg-white/10 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-white/70"
                            ><i class="fas fa-file-alt mr-1"></i>Content</span
                        >
                        <span class="text-xs font-bold {{ $contentPct >= 90 ? 'text-red-300' : 'text-white' }}"
                            >{{ $contentPct }}%</span
                        >
                    </div>
                    <p class="text-2xl font-extrabold">{{ $contentUsed }}<span class="text-sm font-normal text-white/60">/{{ $contentLimit }}</span></p>
                    <div class="mt-2 h-2 bg-white/20 rounded-full overflow-hidden">
                        <div
                            class="h-full rounded-full {{ $contentPct >= 90 ? 'bg-red-400' : ($contentPct >= 70 ? 'bg-amber-400' : 'bg-white') }}"
                            style="width: {{ $contentPct }}%"
                        ></div>
                    </div>
                    <p class="text-[10px] text-white/50 mt-1">{{ $contentLimit - $contentUsed }} remaining this month</p>
                </div>

                {{-- Workflow --}}
                <div class="bg-white/10 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-white/70"
                            ><i class="fas fa-columns mr-1"></i>Workflow</span
                        >
                        <span class="text-xs font-bold {{ $workflowPct >= 90 ? 'text-red-300' : 'text-white' }}"
                            >{{ $workflowPct }}%</span
                        >
                    </div>
                    <p class="text-2xl font-extrabold">{{ $workflowUsed }}<span class="text-sm font-normal text-white/60">/{{ $workflowLimit }}</span></p>
                    <div class="mt-2 h-2 bg-white/20 rounded-full overflow-hidden">
                        <div
                            class="h-full rounded-full {{ $workflowPct >= 90 ? 'bg-red-400' : ($workflowPct >= 70 ? 'bg-amber-400' : 'bg-white') }}"
                            style="width: {{ $workflowPct }}%"
                        ></div>
                    </div>
                    <p class="text-[10px] text-white/50 mt-1">{{ $workflowLimit - $workflowUsed }} remaining this month</p>
                </div>

                {{-- Storage --}}
                <div class="bg-white/10 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-white/70"><i class="fas fa-hdd mr-1"></i>Storage</span>
                        <span class="text-xs font-bold {{ $storagePct >= 90 ? 'text-red-300' : 'text-white' }}"
                            >{{ $storagePct }}%</span
                        >
                    </div>
                    <p class="text-2xl font-extrabold">{{ $storageUsed }}<span class="text-sm font-normal text-white/60">/{{ $storageLimit }}MB</span></p>
                    <div class="mt-2 h-2 bg-white/20 rounded-full overflow-hidden">
                        <div
                            class="h-full rounded-full {{ $storagePct >= 90 ? 'bg-red-400' : ($storagePct >= 70 ? 'bg-amber-400' : 'bg-white') }}"
                            style="width: {{ $storagePct }}%"
                        ></div>
                    </div>
                    <p class="text-[10px] text-white/50 mt-1">{{ $storageLimit - $storageUsed }}MB remaining</p>
                </div>

                {{-- Approvals --}}
                <div class="bg-white/10 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-white/70"
                            ><i class="fas fa-check-double mr-1"></i>Approvals</span
                        >
                    </div>
                    <p class="text-2xl font-extrabold">{{ $approvalUsed }}</p>
                    <p class="text-[10px] text-white/50 mt-3">items submitted for review</p>
                </div>
            </div>

            @if (!empty($packageLimits['included_platforms']))
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="text-xs text-white/60">Platforms:</span>
                    @foreach ($packageLimits['included_platforms'] as $platform)
                        <span
                            class="text-xs px-2 py-0.5 rounded-full bg-white/10 text-white/80"
                            >{{ ucfirst($platform) }}</span
                        >
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left Column: Projects + Approvals --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- My Projects --}}
            <div class="bg-white rounded-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-bold text-gray-900">My Projects</h2>
                    <a
                        href="{{ route('client.workflow') }}"
                        class="text-xs font-semibold text-[var(--brand)] hover:underline"
                        >View all</a
                    >
                </div>
                <div class="p-6">
                    @if ($this->myProjects->isEmpty())
                        <div class="text-center py-8">
                            <i class="fas fa-folder-open text-3xl text-gray-200 mb-3"></i>
                            <p class="text-sm text-gray-400">No projects yet.</p>
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach ($this->myProjects as $project)
                                <div
                                    class="flex items-center gap-4 rounded-xl border border-gray-100 p-4 hover:bg-gray-50 transition-colors"
                                >
                                    <div
                                        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white"
                                        style="background-color: {{ $this->getStageColor($project->stage) }}"
                                    >
                                        <i
                                            class="fas fa-{{ match($project->stage) { 'idea' => 'lightbulb', 'shooting' => 'camera', 'editing' => 'film', 'review' => 'eye', 'published' => 'check', default => 'circle' } }}"
                                        ></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 truncate">{{ $project->title }}</p>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span
                                                class="badge"
                                                style="background-color: {{ $this->getStageColor($project->stage) }}20; color: {{ $this->getStageColor($project->stage) }}"
                                                >{{ $this->getStageName($project->stage) }}</span
                                            >
                                            <span class="text-xs text-gray-400"
                                                ><i class="fas fa-calendar-alt mr-1"></i
                                                >{{ $project->deadline?->format('M d, Y') ?? 'No deadline' }}</span
                                            >
                                        </div>
                                    </div>
                                    <span
                                        class="badge badge-{{ $project->priority }}"
                                        >{{ ucfirst($project->priority) }}</span
                                    >
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Pending Approvals --}}
            <div class="bg-white rounded-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-bold text-gray-900">Pending Approvals</h2>
                    <a
                        href="{{ route('client.approvals') }}"
                        class="text-xs font-semibold text-[var(--brand)] hover:underline"
                        >View all</a
                    >
                </div>
                <div class="p-6">
                    @if ($this->pendingApprovalsList->isEmpty())
                        <div class="text-center py-8">
                            <i class="fas fa-check-double text-3xl text-gray-200 mb-3"></i>
                            <p class="text-sm text-gray-400">No pending approvals. You're all caught up!</p>
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach ($this->pendingApprovalsList as $approval)
                                <a
                                    href="{{ route('client.approvals') }}"
                                    class="flex items-center gap-4 rounded-xl border border-gray-100 p-4 hover:bg-gray-50 transition-colors"
                                >
                                    <div
                                        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600"
                                    >
                                        <i class="fas fa-hourglass-half text-sm"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 truncate">{{ $approval->title }}</p>
                                        <p class="text-xs text-gray-400 mt-0.5">Submitted {{ $approval->created_at->diffForHumans() }}</p>
                                    </div>
                                    <span
                                        class="badge badge-{{ $approval->type }}"
                                        >{{ ucfirst($approval->type) }}</span
                                    >
                                    <span class="badge badge-pending">Pending</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Right Column: Working Hours --}}
        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-bold text-gray-900">Agency Working Hours</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        @foreach ($this->workingHours as $hour)
                            <div
                                class="flex items-center justify-between rounded-xl px-4 py-3 {{ $hour->active ? 'bg-gray-50' : 'bg-gray-50/50 opacity-50' }}"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-2 h-2 rounded-full {{ $hour->active ? 'bg-green-500' : 'bg-gray-300' }}"
                                    ></div>
                                    <span
                                        class="text-sm font-medium {{ $hour->active ? 'text-gray-900' : 'text-gray-400' }}"
                                        >{{ $this->getDayName($hour->day) }}</span
                                    >
                                </div>
                                @if ($hour->active)
                                    <span class="text-xs font-semibold text-gray-600"
                                        >{{ $this->formatTime($hour->start) }} – {{ $this->formatTime($hour->end) }}</span
                                    >
                                @else
                                    <span class="text-xs font-medium text-gray-400">Closed</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Upgrade Package Modal --}}
    @if ($showUpgradeModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
            wire:click.self="$set('showUpgradeModal', false)"
            x-on:keydown.escape.window="$wire.set('showUpgradeModal', false)"
        >
            <div class="modal-box w-full max-w-4xl mx-4 max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b z-10">
                    <div>
                        <h3 class="font-bold text-lg">Upgrade Your Package</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Choose a plan that fits your needs</p>
                    </div>
                    <button wire:click="$set('showUpgradeModal', false)" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        @foreach ($upgradeOptions as $option)
                            @php
                        $isCurrent = $option['is_current'];
                        $isSelected = $selectedUpgrade === $option['slug'];
                    @endphp
                            <div
                                wire:click="{{ $isCurrent ? '' : "selectUpgrade('{$option['slug']}')" }}"
                                class="relative rounded-xl border-2 p-4 transition-all {{ $isCurrent ? 'border-[var(--brand)] bg-[var(--brand)]/5' : ($isSelected ? 'border-[var(--brand)] bg-[var(--brand)]/10 shadow-lg' : 'border-gray-200 hover:border-gray-300 hover:shadow-md cursor-pointer') }}"
                            >
                                @if ($isCurrent)
                                    <span
                                        class="absolute -top-2.5 left-4 bg-[var(--brand)] text-white text-[10px] font-bold px-2 py-0.5 rounded-full"
                                        >CURRENT</span
                                    >
                                @endif

                                @if ($isSelected)
                                    <div class="absolute top-3 right-3">
                                        <div
                                            class="w-5 h-5 rounded-full bg-[var(--brand)] flex items-center justify-center"
                                        >
                                            <i class="fas fa-check text-white text-[10px]"></i>
                                        </div>
                                    </div>
                                @endif

                                <h4 class="font-bold text-base text-gray-900">{{ $option['name'] }}</h4>
                                <p class="text-2xl font-extrabold text-[var(--brand)] mt-2">NPR {{ number_format($option['monthly_amount']) }}<span class="text-xs font-normal text-gray-400">/mo</span></p>

                                <div class="mt-4 space-y-2 text-xs text-gray-600">
                                    <div class="flex items-center gap-2">
                                        <i
                                            class="fas fa-file-alt w-4 text-center {{ $option['content_limit'] >= ($packageLimits['content_limit'] ?? 0) ? 'text-green-500' : 'text-gray-400' }}"
                                        ></i>
                                        <span>{{ $option['content_limit'] }} content/month</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i
                                            class="fas fa-columns w-4 text-center {{ $option['workflow_limit'] >= ($packageLimits['workflow_limit'] ?? 0) ? 'text-green-500' : 'text-gray-400' }}"
                                        ></i>
                                        <span>{{ $option['workflow_limit'] }} workflow items</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i
                                            class="fas fa-hdd w-4 text-center {{ $option['storage_limit_mb'] >= ($packageLimits['storage_limit_mb'] ?? 0) ? 'text-green-500' : 'text-gray-400' }}"
                                        ></i>
                                        <span>{{ $option['storage_limit_mb'] }}MB storage</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i
                                            class="fas fa-redo w-4 text-center {{ $option['revision_limit'] >= ($packageLimits['revision_limit'] ?? 0) ? 'text-green-500' : 'text-gray-400' }}"
                                        ></i>
                                        <span>{{ $option['revision_limit'] }} revisions</span>
                                    </div>
                                    @if ($option['priority_support'])
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-headset w-4 text-center text-amber-500"></i>
                                            <span class="font-medium">Priority Support</span>
                                        </div>
                                    @endif
                                </div>

                                @if (!empty($option['included_platforms']))
                                    <div class="flex flex-wrap gap-1 mt-3">
                                        @foreach (array_slice($option['included_platforms'], 0, 4) as $platform)
                                            <span
                                                class="text-[9px] px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-600"
                                                >{{ ucfirst($platform) }}</span
                                            >
                                        @endforeach
                                        @if (count($option['included_platforms']) > 4)
                                            <span
                                                class="text-[9px] px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-600"
                                                >+{{ count($option['included_platforms']) - 4 }}</span
                                            >
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if (!empty($selectedUpgrade))
                        @php
                    $newPkg = collect($upgradeOptions)->firstWhere('slug', $selectedUpgrade);
                    $priceDiff = $newPkg['monthly_amount'] - ($packageLimits['monthly_amount'] ?? 0);
                @endphp
                        <div class="mt-6 bg-gray-50 rounded-xl p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-700">Upgrading to <span class="font-bold text-[var(--brand)]">{{ $newPkg['name'] }}</span></p>
                                    <p class="text-xs text-gray-500 mt-0.5">Additional NPR {{ number_format($priceDiff) }}/month</p>
                                </div>
                                <button wire:click="confirmUpgrade" class="btn btn-primary">
                                    <i class="fas fa-arrow-up mr-1"></i> Confirm Upgrade
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
