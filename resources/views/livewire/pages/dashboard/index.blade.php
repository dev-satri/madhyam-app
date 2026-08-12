<?php

use App\Services\RbacService;
use App\Support\UserVisibility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $range = 'month';

    public $stats = [];

    public $revenueData = [];

    public $taskDistribution = [];

    public $platformData = [];

    public $stageData = [];

    public $workingHours = [];

    public $workload = [];

    public $deadlines = [];

    public $activities = [];

    public $teamPerformance = [];

    public string $backupReminder = '';

    public string $activityFilter = '';

    public string $activitySearch = '';

    public $allStaff = [];

    public bool $isAdmin = false;

    public function mount(): void
    {
        $this->loadData();
    }

    public function setRange(string $range): void
    {
        $this->range = $range;
        $this->loadData();
    }

    public function loadData(): void
    {
        $user = Auth::user();
        // Manager falls into the staff dashboard view. Only super-admin + admin
        // see agency-wide revenue/clients/workload tiles.
        $this->isAdmin = in_array($user->role ?? '', ['super-admin', 'admin']);
        $userId = $user->id;
        $rbac = new RbacService;
        $canSeeAllActivity = $rbac->hasDataAccess($user->role, 'seeAllActivity');
        $now = now();

        $dateFilter = match ($this->range) {
            'today' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfWeek(),
            default => $now->copy()->startOfMonth(),
        };
        $rangeEnd = match ($this->range) {
            'today' => $now->copy()->endOfDay(),
            'week' => $now->copy()->endOfWeek(),
            default => $now->copy()->endOfMonth(),
        };

        if ($this->isAdmin) {
            $this->stats = [
                'type' => 'admin',
                'clients' => DB::table('clients')
                    ->whereNull('deleted_at')
                    ->where('status', 'active')
                    ->where('created_at', '>=', $dateFilter)
                    ->count(),
                'projects' => DB::table('workflows')->where('created_at', '>=', $dateFilter)->count(),
                'approvals' => DB::table('approvals')->whereNull('deleted_at')->where('created_at', '>=', $dateFilter)->where('status', 'pending')->count(),
                'revenue' => DB::table('invoice_payments')->where('created_at', '>=', $dateFilter)->sum('amount'),
            ];
        } else {
            $this->stats = [
                'type' => 'staff',
                'tasks' => DB::table('tasks')->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode($userId)])->where('created_at', '>=', $dateFilter)->count(),
                'workflows' => DB::table('workflows')->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode($userId)])->where('created_at', '>=', $dateFilter)->count(),
                'approvals' => DB::table('approvals')->whereNull('deleted_at')->where('submitted_by', $userId)->where('created_at', '>=', $dateFilter)->where('status', 'pending')->count(),
                'overdue' => DB::table('tasks')->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode($userId)])->where('due_date', '<', now())->where('status', '!=', 'completed')->count(),
            ];
        }

        // Revenue chart follows the active range filter.
        //   today → group by hour (00..23)
        //   week  → group by day (Mon..Sun)
        //   month → group by day of month
        $revBucket = match ($this->range) {
            'today' => "DATE_FORMAT(created_at, '%H:00')",
            'week'  => "DATE_FORMAT(created_at, '%a')",
            default => "DATE_FORMAT(created_at, '%b %d')",
        };
        $this->revenueData = DB::table('invoice_payments')
            ->select(DB::raw("$revBucket as bucket"), DB::raw('SUM(amount) as total'))
            ->where('created_at', '>=', $dateFilter)
            ->where('created_at', '<=', $rangeEnd)
            ->groupBy('bucket')
            ->orderByRaw('MIN(created_at)')
            ->get()
            ->pluck('total', 'bucket')
            ->toArray();

        if ($this->isAdmin) {
            $this->taskDistribution = DB::table('tasks')
                ->where('created_at', '>=', $dateFilter)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status')
                ->toArray();

            // Flat-count JSON-array platform column, expanding "all" sentinel to every canonical platform
            $platformRows = DB::table('contents')
                ->where('created_at', '>=', $dateFilter)
                ->pluck('platform');
            $platformCounts = [];
            foreach ($platformRows as $raw) {
                $expanded = \App\Support\ContentTags::expand(
                    \App\Support\ContentTags::normalize($raw, 'platform'),
                    'platform'
                );
                foreach ($expanded as $v) {
                    $platformCounts[$v] = ($platformCounts[$v] ?? 0) + 1;
                }
            }
            arsort($platformCounts);
            $this->platformData = $platformCounts;

            $this->stageData = DB::table('workflows')
                ->select('stage', DB::raw('COUNT(*) as count'))
                ->groupBy('stage')
                ->get()
                ->pluck('count', 'stage')
                ->toArray();
        } else {
            $this->taskDistribution = DB::table('tasks')
                ->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode($userId)])
                ->where('created_at', '>=', $dateFilter)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status')
                ->toArray();
        }

        $today = strtolower(substr(now()->englishDayOfWeek, 0, 3));
        $this->workingHours = DB::table('working_hours')
            ->where('day', $today)
            ->first();

        if ($this->isAdmin) {
            $this->workload = UserVisibility::apply(
                DB::table('users')
                    ->leftJoin('tasks', function ($join) use ($dateFilter) {
                        $join->on(DB::raw('JSON_CONTAINS(tasks.assignee, CAST(users.id AS JSON))'), '=', DB::raw('1'))
                            ->where('tasks.created_at', '>=', $dateFilter);
                    })
                    ->select(
                        'users.id',
                        'users.name',
                        'users.role',
                        DB::raw("COUNT(CASE WHEN tasks.status IN ('todo','in-progress') THEN 1 END) as assigned"),
                        DB::raw("COUNT(CASE WHEN tasks.status = 'in-progress' THEN 1 END) as in_progress"),
                        DB::raw("COUNT(CASE WHEN tasks.status = 'completed' THEN 1 END) as completed"),
                        DB::raw("COUNT(CASE WHEN tasks.due_date < CURDATE() AND tasks.status != 'completed' THEN 1 END) as overdue")
                    )
                    ->groupBy('users.id', 'users.name', 'users.role')
            )->get();
        }

        $this->deadlines = collect();

        if ($this->isAdmin) {
            $workflowDeadlines = DB::table('workflows')
                ->whereNotNull('deadline')
                ->where('deadline', '>=', $now)
                ->where('deadline', '<=', $rangeEnd)
                ->orderBy('deadline')
                ->limit(10)
                ->get()
                ->map(fn ($w) => (object) ['type' => 'workflow', 'title' => $w->title, 'date' => $w->deadline, 'client_id' => $w->client_id]);
            $taskDeadlines = DB::table('tasks')
                ->whereNotNull('due_date')
                ->where('due_date', '>=', $now)
                ->where('due_date', '<=', $rangeEnd)
                ->where('status', '!=', 'completed')
                ->orderBy('due_date')
                ->limit(10)
                ->get()
                ->map(fn ($t) => (object) ['type' => 'task', 'title' => $t->title, 'date' => $t->due_date]);
        } else {
            $workflowDeadlines = DB::table('workflows')
                ->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode($userId)])
                ->whereNotNull('deadline')
                ->where('deadline', '>=', $now)
                ->where('deadline', '<=', $rangeEnd)
                ->orderBy('deadline')
                ->limit(10)
                ->get()
                ->map(fn ($w) => (object) ['type' => 'workflow', 'title' => $w->title, 'date' => $w->deadline]);
            $taskDeadlines = DB::table('tasks')
                ->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode($userId)])
                ->whereNotNull('due_date')
                ->where('due_date', '>=', $now)
                ->where('due_date', '<=', $rangeEnd)
                ->where('status', '!=', 'completed')
                ->orderBy('due_date')
                ->limit(10)
                ->get()
                ->map(fn ($t) => (object) ['type' => 'task', 'title' => $t->title, 'date' => $t->due_date]);
        }

        $this->deadlines = $workflowDeadlines->concat($taskDeadlines)->sortBy('date')->take(10)->values();

        $actQ = DB::table('activity_logs')
            ->leftJoin('users', 'activity_logs.user_id', '=', 'users.id')
            ->select('activity_logs.*', 'users.name as user_name')
            ->where('activity_logs.time', '>=', $dateFilter);

        // Hide super-admin activity rows from non-super-admin viewers.
        // Left-joined rows (deleted users / system actions) still show through the null check.
        if (! UserVisibility::canSeeSuperAdmins($user)) {
            $actQ->where(function ($q) {
                $q->whereNull('users.role')->orWhere('users.role', '!=', 'super-admin');
            });
        }

        if (! $canSeeAllActivity) {
            $actQ->where('activity_logs.user_id', $userId);
        }
        if ($this->activityFilter) {
            $actQ->where('activity_logs.user_id', $this->activityFilter);
        }
        if ($this->activitySearch) {
            $actQ->where('activity_logs.text', 'like', "%{$this->activitySearch}%");
        }
        $this->activities = $actQ->orderBy('activity_logs.time', 'desc')->limit(20)->get();

        $this->allStaff = UserVisibility::apply(
            DB::table('users')->select('id', 'name')
        )->orderBy('name')->get();

        if ($this->isAdmin) {
            $this->teamPerformance = UserVisibility::apply(
                DB::table('users')
                    ->leftJoin('tasks', function ($join) use ($dateFilter) {
                        $join->on(DB::raw('JSON_CONTAINS(tasks.assignee, CAST(users.id AS JSON))'), '=', DB::raw('1'))
                            ->where('tasks.created_at', '>=', $dateFilter);
                    })
                    ->select(
                        'users.id',
                        'users.name',
                        'users.role',
                        DB::raw('COUNT(tasks.id) as total_tasks'),
                        DB::raw("COUNT(CASE WHEN tasks.status = 'completed' THEN 1 END) as completed_tasks")
                    )
                    ->groupBy('users.id', 'users.name', 'users.role')
                    ->havingRaw('COUNT(tasks.id) > 0')
            )->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $m->role,
                    'total' => $m->total_tasks,
                    'completed' => $m->completed_tasks,
                    'pct' => $m->total_tasks > 0 ? round(($m->completed_tasks / $m->total_tasks) * 100) : 0,
                ]);
        } else {
            $totalTasks = DB::table('tasks')->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode($userId)])->where('created_at', '>=', $dateFilter)->count();
            $completedTasks = DB::table('tasks')->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode($userId)])->where('status', 'completed')->where('created_at', '>=', $dateFilter)->count();
            $this->teamPerformance = collect();
            if ($totalTasks > 0) {
                $this->teamPerformance = collect([[
                    'id' => $userId,
                    'name' => $user->name,
                    'role' => $user->role,
                    'total' => $totalTasks,
                    'completed' => $completedTasks,
                    'pct' => round(($completedTasks / $totalTasks) * 100),
                ]]);
            }
        }

        $settings = DB::table('settings')->first();
        if ($settings && $this->isAdmin) {
            $lastReminder = $settings->last_backup_reminder ?? null;
            if (! $lastReminder || now()->diffInDays(Carbon::parse($lastReminder)) > ($settings->backup_reminder_days ?? 7)) {
                $this->backupReminder = 'Reminder: Please backup your data from Settings → Data & Backup';
            }
        }

        $this->dispatch('dashboard-data', revenueData: $this->revenueData, taskDist: $this->taskDistribution, platformData: $this->platformData, stageData: $this->stageData, isAdmin: $this->isAdmin);
    }

    public function dismissBackup(): void
    {
        $this->backupReminder = '';
    }

    public function updatedActivityFilter(): void
    {
        $this->loadData();
    }

    public function updatedActivitySearch(): void
    {
        $this->loadData();
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6" x-data="dashboardInit()" wire:loading.class="opacity-60" wire:target="setRange, loadData">
        <script>
            function dashboardInit() {
                let charts = {};
                return {
                    init() {
                        this.buildCharts();
                        this.$wire.on('dashboard-data', (data) => {
                            this.buildCharts(data.revenueData, data.taskDist, data.platformData, data.stageData, data.isAdmin);
                        });
                    },
                    buildCharts(revenueData, taskDist, platformData, stageData, isAdmin) {
                        revenueData = revenueData || (@js($revenueData ?? []));
                        taskDist = taskDist || (@js($taskDistribution ?? []));
                        platformData = platformData || (@js($platformData ?? []));
                        stageData = stageData || (@js($stageData ?? []));
                        isAdmin = isAdmin !== undefined ? isAdmin : (@js($isAdmin ?? false));

                        Object.values(charts).forEach(c => { try { c.destroy(); } catch (_) {} });
                        charts = {};
                        const brand = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#4f46e5';

                        const rCtx = document.getElementById('revenueChart');
                        if (rCtx && revenueData && Object.keys(revenueData).length) {
                            const labels = Object.keys(revenueData);
                            const data = Object.values(revenueData);
                            const gradient = rCtx.getContext('2d').createLinearGradient(0, 0, 0, 220);
                            gradient.addColorStop(0, brand + '40');
                            gradient.addColorStop(1, brand + '05');
                            charts.revenue = new Chart(rCtx, {
                                type: 'line',
                                data: { labels, datasets: [{ label: 'Revenue', data, borderColor: brand, backgroundColor: gradient, fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: brand }] },
                                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } } }
                            });
                        }

                        const tCtx = document.getElementById('taskChart');
                        if (tCtx && taskDist && Object.keys(taskDist).length) {
                            const labels = Object.keys(taskDist).map(k => k.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()));
                            const data = Object.values(taskDist);
                            const colors = ['#94a3b8', '#3b82f6', '#22c55e', '#ef4444', '#f59e0b', '#8b5cf6'];
                            charts.tasks = new Chart(tCtx, {
                                type: 'doughnut',
                                data: { labels, datasets: [{ data, backgroundColor: colors.slice(0, data.length), borderWidth: 0 }] },
                                options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'right', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }, datalabels: { display: false } } }
                            });
                        }

                        if (isAdmin) {
                            const pCtx = document.getElementById('platformChart');
                            if (pCtx && platformData && Object.keys(platformData).length) {
                                const labels = Object.keys(platformData).map(k => k.charAt(0).toUpperCase() + k.slice(1));
                                const data = Object.values(platformData);
                                const colors = ['#E1306C', '#1877F2', '#000000', '#FF0000', '#1DA1F2', '#0A66C2'];
                                charts.platform = new Chart(pCtx, {
                                    type: 'bar',
                                    data: { labels, datasets: [{ label: 'Content', data, backgroundColor: colors, borderRadius: 6, barThickness: 28 }] },
                                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } } }
                                });
                            }

                            const sCtx = document.getElementById('stageChart');
                            if (sCtx && stageData && Object.keys(stageData).length) {
                                const labels = Object.keys(stageData).map(k => k.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()));
                                const data = Object.values(stageData);
                                charts.stage = new Chart(sCtx, {
                                    type: 'bar',
                                    data: { labels, datasets: [{ label: 'Items', data, backgroundColor: brand, borderRadius: 6, barThickness: 28 }] },
                                    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { color: '#f1f5f9' } }, y: { grid: { display: false } } } }
                                });
                            }
                        }
                    }
                };
            }
        </script>
            {{-- Backup Reminder --}}
            @if($backupReminder)
            <div class="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <i class="fas fa-exclamation-triangle text-amber-500"></i>
                <span class="flex-1">{{ $backupReminder }}</span>
                <button wire:click="dismissBackup" class="text-amber-600 hover:text-amber-800"><i class="fas fa-times"></i></button>
            </div>
            @endif

            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Dashboard</h1>
                    <p class="text-sm text-gray-500">Welcome back, here's your overview.</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5">
                        <button wire:click="setRange('today')" class="px-3 py-1.5 text-xs font-semibold rounded-md {{ $range === 'today' ? 'bg-[var(--brand)] text-white' : 'text-gray-500 hover:bg-gray-50' }}" wire:loading.attr="disabled" wire:target="setRange">Today</button>
                        <button wire:click="setRange('week')" class="px-3 py-1.5 text-xs font-semibold rounded-md {{ $range === 'week' ? 'bg-[var(--brand)] text-white' : 'text-gray-500 hover:bg-gray-50' }}" wire:loading.attr="disabled" wire:target="setRange">Week</button>
                        <button wire:click="setRange('month')" class="px-3 py-1.5 text-xs font-semibold rounded-md {{ $range === 'month' ? 'bg-[var(--brand)] text-white' : 'text-gray-500 hover:bg-gray-50' }}" wire:loading.attr="disabled" wire:target="setRange">Month</button>
                    </div>
                    <button wire:click="loadData" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="loadData">
                        <i wire:loading.remove wire:target="loadData" class="fas fa-sync-alt text-xs"></i>
                        <i wire:loading wire:target="loadData" class="fas fa-spinner animate-spin text-xs"></i>
                        Refresh
                    </button>
                </div>
            </div>

            {{-- Stat Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @if(($stats['type'] ?? 'admin') === 'admin')
                <div class="stat-card flex items-center gap-4">
                    <div class="stat-icon flex h-11 w-11 items-center justify-center rounded-xl bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]"><i class="fas fa-users text-lg"></i></div>
                    <div><p class="stat-label text-xs font-medium text-gray-500">Active Clients</p><p class="stat-value text-2xl font-extrabold text-gray-900">{{ number_format($stats['clients'] ?? 0) }}</p></div>
                </div>
                <div class="stat-card flex items-center gap-4">
                    <div class="stat-icon flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-project-diagram text-lg"></i></div>
                    <div><p class="stat-label text-xs font-medium text-gray-500">Active Projects</p><p class="stat-value text-2xl font-extrabold text-gray-900">{{ number_format($stats['projects'] ?? 0) }}</p></div>
                </div>
                <div class="stat-card flex items-center gap-4">
                    <div class="stat-icon flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50 text-amber-600"><i class="fas fa-check-double text-lg"></i></div>
                    <div><p class="stat-label text-xs font-medium text-gray-500">Pending Approvals</p><p class="stat-value text-2xl font-extrabold text-gray-900">{{ number_format($stats['approvals'] ?? 0) }}</p></div>
                </div>
                <div class="stat-card flex items-center gap-4">
                    <div class="stat-icon flex h-11 w-11 items-center justify-center rounded-xl bg-green-50 text-green-600"><i class="fas fa-dollar-sign text-lg"></i></div>
                    <div><p class="stat-label text-xs font-medium text-gray-500">Revenue</p><p class="stat-value text-2xl font-extrabold text-gray-900">NPR {{ number_format($stats['revenue'] ?? 0) }}</p></div>
                </div>
                @else
                <div class="stat-card flex items-center gap-4">
                    <div class="stat-icon flex h-11 w-11 items-center justify-center rounded-xl bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]"><i class="fas fa-tasks text-lg"></i></div>
                    <div><p class="stat-label text-xs font-medium text-gray-500">My Tasks</p><p class="stat-value text-2xl font-extrabold text-gray-900">{{ number_format($stats['tasks'] ?? 0) }}</p></div>
                </div>
                <div class="stat-card flex items-center gap-4">
                    <div class="stat-icon flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-project-diagram text-lg"></i></div>
                    <div><p class="stat-label text-xs font-medium text-gray-500">My Workflows</p><p class="stat-value text-2xl font-extrabold text-gray-900">{{ number_format($stats['workflows'] ?? 0) }}</p></div>
                </div>
                <div class="stat-card flex items-center gap-4">
                    <div class="stat-icon flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50 text-amber-600"><i class="fas fa-check-double text-lg"></i></div>
                    <div><p class="stat-label text-xs font-medium text-gray-500">Pending Approvals</p><p class="stat-value text-2xl font-extrabold text-gray-900">{{ number_format($stats['approvals'] ?? 0) }}</p></div>
                </div>
                <div class="stat-card flex items-center gap-4">
                    <div class="stat-icon flex h-11 w-11 items-center justify-center rounded-xl bg-red-50 text-red-600"><i class="fas fa-exclamation-circle text-lg"></i></div>
                    <div><p class="stat-label text-xs font-medium text-gray-500">Overdue</p><p class="stat-value text-2xl font-extrabold {{ ($stats['overdue'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($stats['overdue'] ?? 0) }}</p></div>
                </div>
                @endif
            </div>

            {{-- Personal Performance (Non-Admin) --}}
            @if(($stats['type'] ?? 'admin') === 'staff')
            <div class="bg-white rounded-2xl border border-gray-100 p-5">
                <h3 class="text-sm font-bold text-gray-900 mb-4">My Performance</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    @php
                        $myTasks = $stats['tasks'] ?? 0;
                        $myCompleted = DB::table('tasks')->whereRaw('JSON_CONTAINS(assignee, ?)', [json_encode(Auth::id())])->where('status', 'completed')->count();
                        $myOverdue = $stats['overdue'] ?? 0;
                        $myWorkflows = $stats['workflows'] ?? 0;
                        $completionPct = $myTasks > 0 ? round(($myCompleted / $myTasks) * 100) : 0;
                    @endphp
                    <div class="text-center p-3 rounded-xl bg-gray-50">
                        <p class="text-2xl font-extrabold text-gray-900">{{ $myTasks }}</p>
                        <p class="text-xs text-gray-500 mt-1">Total Tasks</p>
                    </div>
                    <div class="text-center p-3 rounded-xl bg-green-50">
                        <p class="text-2xl font-extrabold text-green-600">{{ $myCompleted }}</p>
                        <p class="text-xs text-gray-500 mt-1">Completed</p>
                    </div>
                    <div class="text-center p-3 rounded-xl bg-red-50">
                        <p class="text-2xl font-extrabold text-red-600">{{ $myOverdue }}</p>
                        <p class="text-xs text-gray-500 mt-1">Overdue</p>
                    </div>
                    <div class="text-center p-3 rounded-xl bg-blue-50">
                        <p class="text-2xl font-extrabold text-blue-600">{{ $myWorkflows }}</p>
                        <p class="text-xs text-gray-500 mt-1">Workflows</p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                        <span>Completion Rate</span>
                        <span class="font-semibold {{ $completionPct >= 80 ? 'text-green-600' : ($completionPct >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $completionPct }}%</span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill {{ $completionPct >= 80 ? 'bg-green-500' : ($completionPct >= 50 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ $completionPct }}%"></div></div>
                </div>
            </div>
            @endif

            {{-- Charts Row --}}
            @if($isAdmin)
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-2xl border border-gray-100 p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-4">Revenue Trend</h3>
                    <div style="height:220px"><canvas id="revenueChart"></canvas></div>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-4">Task Distribution</h3>
                    <div style="height:220px"><canvas id="taskChart"></canvas></div>
                </div>
            </div>
            @else
            <div class="bg-white rounded-2xl border border-gray-100 p-5">
                <h3 class="text-sm font-bold text-gray-900 mb-4">My Task Status</h3>
                <div style="height:220px"><canvas id="taskChart"></canvas></div>
            </div>
            @endif

            @if($isAdmin)
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-2xl border border-gray-100 p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-4">Content by Platform</h3>
                    <div style="height:220px"><canvas id="platformChart"></canvas></div>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-4">Workflow Pipeline</h3>
                    <div style="height:220px"><canvas id="stageChart"></canvas></div>
                </div>
            </div>
            @endif

            {{-- Working Hours + Deadlines --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white rounded-2xl border border-gray-100 p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">Working Hours</h3>
                    @if($workingHours)
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm"><span class="text-gray-500">Day</span><span class="font-semibold text-gray-900">{{ ucfirst($workingHours->day ?? '') }}</span></div>
                        <div class="flex justify-between text-sm"><span class="text-gray-500">Hours</span><span class="font-semibold text-gray-900">{{ $workingHours->start ?? '—' }} – {{ $workingHours->end ?? '—' }}</span></div>
                        <div class="flex justify-between text-sm"><span class="text-gray-500">Status</span><span class="badge {{ ($workingHours->active ?? false) ? 'badge-active' : 'badge-inactive' }}">{{ ($workingHours->active ?? false) ? 'Open' : 'Closed' }}</span></div>
                    </div>
                    @else <p class="text-sm text-gray-400">No schedule set</p> @endif
                </div>

                <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">{{ $isAdmin ? 'Upcoming Deadlines' : 'My Deadlines' }}</h3>
                    @if($deadlines->count())
                    <div class="space-y-2">
                        @foreach($deadlines as $d)
                        <div class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-gray-50">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg {{ $d->type === 'workflow' ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600' }}">
                                <i class="fas {{ $d->type === 'workflow' ? 'fa-project-diagram' : 'fa-tasks' }} text-xs"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $d->title }}</p>
                                <p class="text-[11px] text-gray-400">{{ \App\Support\NepaliDate::display($d->date) }}</p>
                            </div>
                            @if(\Carbon\Carbon::parse($d->date)->isPast())
                                <span class="badge badge-overdue text-[10px]">Overdue</span>
                            @endif
                        </div>
                        @endforeach
                    </div>
                    @else <p class="text-sm text-gray-400 py-4 text-center">No upcoming deadlines</p> @endif
                </div>
            </div>

            {{-- Workload Table (Admin only) --}}
            @if($isAdmin)
            <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900">Staff Workload</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Role</th><th>Assigned</th><th>In Progress</th><th>Completed</th><th>Overdue</th></tr></thead>
                        <tbody>
                            @forelse($workload as $w)
                            <tr class="cursor-pointer">
                                <td class="font-medium">{{ $w->name }}</td>
                                <td><span class="badge role-{{ $w->role }}">{{ ucfirst(str_replace('-', ' ', $w->role)) }}</span></td>
                                <td>{{ $w->assigned }}</td>
                                <td>{{ $w->in_progress }}</td>
                                <td class="text-green-600 font-medium">{{ $w->completed }}</td>
                                <td class="{{ $w->overdue > 0 ? 'text-red-600 font-medium' : '' }}">{{ $w->overdue }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center py-8 text-gray-400 text-sm">No workload data</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- Team Performance Progress Bars --}}
            @if($teamPerformance->count())
            <div class="bg-white rounded-2xl border border-gray-100 p-5">
                <h3 class="text-sm font-bold text-gray-900 mb-4">{{ $isAdmin ? 'Team Performance' : 'My Completion' }}</h3>
                <div class="space-y-3">
                    @foreach($teamPerformance as $m)
                    <div class="flex items-center gap-4">
                        <div class="w-32 shrink-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $m['name'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ $m['completed'] }}/{{ $m['total'] }} tasks</p>
                        </div>
                        <div class="flex-1">
                            <div class="progress-bar"><div class="progress-fill {{ $m['pct'] >= 80 ? 'bg-green-500' : ($m['pct'] >= 50 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ $m['pct'] }}%"></div></div>
                        </div>
                        <span class="text-sm font-bold {{ $m['pct'] >= 80 ? 'text-green-600' : ($m['pct'] >= 50 ? 'text-amber-600' : 'text-red-600') }} w-12 text-right">{{ $m['pct'] }}%</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Activity Trace with Filters --}}
            <div class="bg-white rounded-2xl border border-gray-100 p-5">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                    <h3 class="text-sm font-bold text-gray-900">Recent Activity</h3>
                    <div class="flex items-center gap-2">
                        <select wire:model.live="activityFilter" class="form-select text-xs py-1.5 px-3">
                            <option value="">{{ $isAdmin ? 'All Staff' : 'My Activity' }}</option>
                            @foreach($allStaff as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                        <x-search-input wire="activitySearch" placeholder="Search activity..." compact class="w-48" />
                    </div>
                </div>
                @if($activities->count())
                <div class="space-y-2 max-h-64 overflow-y-auto">
                    @foreach($activities as $a)
                    <div class="flex items-start gap-3 px-3 py-2 rounded-lg hover:bg-gray-50">
                        <div class="mt-1 h-2 w-2 rounded-full bg-[var(--brand)] shrink-0"></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-700"><span class="font-semibold">{{ $a->user_name ?? $a->user ?? 'System' }}</span> {{ $a->text ?? '' }}</p>
                            <p class="text-[11px] text-gray-400">{{ $a->time ? \Carbon\Carbon::parse($a->time)->diffForHumans() : '' }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
                @else <p class="text-sm text-gray-400 text-center py-4">No activity found</p> @endif
            </div>
        </div>
        blade;
    }
};
