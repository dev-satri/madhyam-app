<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use App\Models\Workflow;
use App\Models\Approval;
use App\Models\Content;
use App\Models\WorkingHour;
use App\Models\WorkflowStage;
use App\Services\PackageService;

new #[Layout('components.layouts.app')] class extends Component {
    private mixed $cachedClient = null;
    private ?\Illuminate\Support\Collection $cachedStages = null;
    public array $packageLimits = [];
    public array $packageUsage = [];
    public array $packageAlerts = [];
    // Per-deliverable-type breakdown: [{type, used, limit, percent, over}, ...].
    public array $packageDeliverables = [];

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
        $this->packageDeliverables = $data['deliverables'] ?? [];
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
        return Workflow::where('client_id', $client->id)->whereNotIn('stage', ['published', 'ready-for-production'])->count();
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

    public function getMyContentProperty(): \Illuminate\Database\Eloquent\Collection
    {
        $client = $this->getClient();
        if (! $client) return collect();
        return Content::where('client_id', $client->id)->latest()->take(10)->get();
    }

    public function getPendingApprovalsListProperty(): \Illuminate\Database\Eloquent\Collection
    {
        $client = $this->getClient();
        if (! $client) return collect();
        return Approval::where('client_id', $client->id)->where('status', 'pending')->latest()->take(5)->get();
    }

    public function getStageColor(string $key): string
    {
        return $this->cachedStages?->get($key)?->color ?? '#64748b';
    }

    public function getStageName(string $key): string
    {
        return $this->cachedStages?->get($key)?->name ?? ucfirst($key);
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

    {{-- My Content --}}
    @php $myContent = $this->myContent; @endphp
    @if ($myContent->count())
        <div class="bg-white rounded-2xl border border-gray-100 p-5 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-gray-900">My Content</h3>
                <a href="{{ route('client.complaints') }}" class="text-xs text-[var(--brand)] hover:underline"
                    >View All</a
                >
            </div>
            <div class="space-y-2">
                @foreach ($myContent as $c)
                    @php
                        $statusBadge = match($c->status) {
                            'draft' => 'bg-gray-100 text-gray-700',
                            'scripting' => 'bg-purple-100 text-purple-700',
                            'in-review' => 'bg-amber-100 text-amber-700',
                            'revision' => 'bg-orange-100 text-orange-700',
                            'published' => 'bg-green-100 text-green-700',
                            default => 'bg-gray-100 text-gray-700',
                        };
                        $platformIcon = match($c->platform) { 'instagram' => 'camera', 'facebook' => 'globe', 'tiktok' => 'music', 'youtube' => 'play', default => 'file' };
                    @endphp
                    <div class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50">
                        <div
                            class="w-8 h-8 rounded-lg bg-{{ $c->platform }}-500/10 flex items-center justify-center flex-shrink-0"
                        >
                            <i class="fas fa-{{ $platformIcon }} text-{{ $c->platform }}-500 text-xs"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $c->title }}</p>
                            <p class="text-xs text-gray-500">{{ ucfirst($c->platform) }} · {{ ucfirst($c->type) }} · {{ $c->date?->format('M d') }}</p>
                        </div>
                        <span
                            class="text-[10px] font-semibold px-2 py-0.5 rounded {{ $statusBadge }}"
                            >{{ str_replace('-', ' ', ucfirst($c->status)) }}</span
                        >
                    </div>
                @endforeach
            </div>
        </div>
    @endif

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
                </div>
            @endforeach
        </div>
    @endif

    {{-- My Package Usage --}}
    @if (!empty($packageLimits))
        <div class="bg-white rounded-2xl border border-gray-100 p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span
                            class="badge bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]"
                            >{{ $packageLimits['package_name'] ?? 'Basic' }}</span
                        >
                        @if ($packageLimits['priority_support'] ?? false)
                            <span class="badge bg-amber-100 text-amber-700"
                                ><i class="fas fa-headset mr-1"></i>Priority Support</span
                            >
                        @endif
                    </div>
                    <h3 class="text-lg font-bold mt-2 text-gray-900">
                        Your Package: {{ $packageLimits['package_name'] ?? 'Basic' }}
                    </h3>
                    <p class="text-sm text-gray-500">NPR {{ number_format($packageLimits['monthly_amount'] ?? 0) }}/month</p>
                </div>
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
                <div class="rounded-xl border border-gray-100 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500"
                            ><i class="fas fa-file-alt mr-1"></i>Content</span
                        >
                        <span class="text-xs font-bold {{ $contentPct >= 90 ? 'text-red-600' : 'text-gray-900' }}"
                            >{{ $contentPct }}%</span
                        >
                    </div>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $contentUsed }}<span class="text-sm font-normal text-gray-400">/{{ $contentLimit }}</span></p>
                    <div class="mt-2 h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div
                            class="h-full rounded-full {{ $contentPct >= 90 ? 'bg-red-500' : ($contentPct >= 70 ? 'bg-amber-500' : 'bg-[var(--brand)]') }}"
                            style="width: {{ $contentPct }}%"
                        ></div>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">{{ $contentLimit - $contentUsed }} remaining this month</p>
                </div>

                {{-- Workflow --}}
                <div class="rounded-xl border border-gray-100 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500"
                            ><i class="fas fa-columns mr-1"></i>Workflow</span
                        >
                        <span class="text-xs font-bold {{ $workflowPct >= 90 ? 'text-red-600' : 'text-gray-900' }}"
                            >{{ $workflowPct }}%</span
                        >
                    </div>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $workflowUsed }}<span class="text-sm font-normal text-gray-400">/{{ $workflowLimit }}</span></p>
                    <div class="mt-2 h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div
                            class="h-full rounded-full {{ $workflowPct >= 90 ? 'bg-red-500' : ($workflowPct >= 70 ? 'bg-amber-500' : 'bg-[var(--brand)]') }}"
                            style="width: {{ $workflowPct }}%"
                        ></div>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">{{ $workflowLimit - $workflowUsed }} remaining this month</p>
                </div>

                {{-- Storage --}}
                <div class="rounded-xl border border-gray-100 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500"><i class="fas fa-hdd mr-1"></i>Storage</span>
                        <span class="text-xs font-bold {{ $storagePct >= 90 ? 'text-red-600' : 'text-gray-900' }}"
                            >{{ $storagePct }}%</span
                        >
                    </div>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $storageUsed }}<span class="text-sm font-normal text-gray-400">/{{ $storageLimit }}MB</span></p>
                    <div class="mt-2 h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div
                            class="h-full rounded-full {{ $storagePct >= 90 ? 'bg-red-500' : ($storagePct >= 70 ? 'bg-amber-500' : 'bg-[var(--brand)]') }}"
                            style="width: {{ $storagePct }}%"
                        ></div>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">{{ $storageLimit - $storageUsed }}MB remaining</p>
                </div>

                {{-- Approvals --}}
                <div class="rounded-xl border border-gray-100 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500"
                            ><i class="fas fa-check-double mr-1"></i>Approvals</span
                        >
                    </div>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $approvalUsed }}</p>
                    <p class="text-[10px] text-gray-400 mt-3">items submitted for review</p>
                </div>
            </div>

            @if (!empty($packageDeliverables))
                <div class="mt-5 pt-5 border-t border-gray-100">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">
                        <i class="fas fa-box-open mr-1"></i>Deliverables this month
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        @foreach ($packageDeliverables as $d)
                            @php
                                $pct = $d['percent'];
                                $barCls = $pct >= 100 ? 'bg-red-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-[var(--brand)]');
                                $pctCls = $pct >= 100 ? 'text-red-600' : ($pct >= 80 ? 'text-amber-600' : 'text-gray-900');
                                $remaining = max(0, $d['limit'] - $d['used']);
                            @endphp
                            <div class="rounded-xl border border-gray-100 p-3">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-medium text-gray-500">{{ ucfirst($d['type']) }}</span>
                                    <span class="text-xs font-bold {{ $pctCls }}">{{ $pct }}%</span>
                                </div>
                                <p class="text-lg font-extrabold text-gray-900">{{ $d['used'] }}<span class="text-xs font-normal text-gray-400">/{{ $d['limit'] }}</span></p>
                                <div class="mt-1.5 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full {{ $barCls }}" style="width: {{ $pct }}%"></div>
                                </div>
                                <p class="text-[10px] text-gray-400 mt-1">{{ $remaining }} left</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (!empty($packageLimits['included_platforms']))
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="text-xs text-gray-500">Platforms:</span>
                    @foreach ($packageLimits['included_platforms'] as $platform)
                        <span
                            class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"
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
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-bold text-gray-900">My Projects</h2>
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
                                @php
                                    $stageIcon = match($project->stage) { 'idea' => 'lightbulb', 'shooting' => 'camera', 'editing' => 'film', 'review' => 'eye', 'published' => 'check', default => 'circle' };
                                @endphp
                                <div
                                    class="flex items-center gap-4 rounded-xl border border-gray-100 p-4 hover:bg-gray-50 transition-colors"
                                >
                                    <div
                                        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white"
                                        style="background-color: {{ $this->getStageColor($project->stage) }}"
                                    >
                                        <i class="fas fa-{{ $stageIcon }}"></i>
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
</div>
