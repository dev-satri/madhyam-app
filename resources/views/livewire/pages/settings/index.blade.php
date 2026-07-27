<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\CustomRole;
use App\Services\RbacService;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $activeTab = 'general';

    // General
    public string $agencyName = '';
    public string $agencyEmail = '';
    public string $agencyPhone = '';
    public string $currency = 'NPR';
    public string $brandColor = '#4f46e5';
    public int $fileRetentionDays = 5;
    public float $baseSalaryDefault = 25000;
    public float $overtimeRateDefault = 500;
    public int $paidLeavesPerYear = 12;
    public int $workingDaysPerMonth = 22;
    public float $dailyWageDivisor = 30;

    // Working hours
    public array $workingHours = [];

    // Feature access
    public array $featureMatrix = [];
    public array $allRoles = [];
    public array $allFeatures = [];

    // Data access
    public array $dataMatrix = [];
    public array $allPermissions = [];

    // Role manager
    public bool $showRoleForm = false;
    public int $editingRoleId = 0;
    public string $formRoleName = '';
    public string $formRoleDesc = '';
    public array $formRoleFeatures = [];
    public array $formRolePerms = [];

    // Backup tab
    public int $backupIntervalDays = 7;
    public bool $showClearConfirm = false;
    public $importFile = null;

    public function mount(): void
    {
        $settings = DB::table('settings')->where('id', 1)->first();
        if ($settings) {
            $this->agencyName = $settings->agency_name;
            $this->agencyEmail = $settings->agency_email ?? '';
            $this->agencyPhone = $settings->agency_phone ?? '';
            $this->currency = $settings->currency;
            $this->brandColor = $settings->brand_color;
            $this->fileRetentionDays = $settings->file_retention_days;
            $this->baseSalaryDefault = (float) $settings->base_salary_default;
            $this->overtimeRateDefault = (float) $settings->overtime_rate_default;
            $this->paidLeavesPerYear = (int) ($settings->paid_leaves_per_year ?? 12);
            $this->workingDaysPerMonth = (int) ($settings->working_days_per_month ?? 22);
            $this->dailyWageDivisor = (float) ($settings->daily_wage_divisor ?? 30);
            $this->backupIntervalDays = $settings->backup_reminder_days ?? 7;
        }

        $days = ['mon','tue','wed','thu','fri','sat','sun'];
        foreach ($days as $day) {
            $row = DB::table('working_hours')->where('day', $day)->first();
            $this->workingHours[$day] = [
                'active' => $row ? $row->active : !in_array($day, ['sat','sun']),
                'start' => $row ? $row->start : '09:00',
                'end' => $row ? $row->end : '17:00',
            ];
        }

        $this->allFeatures = RbacService::FEATURES;
        $this->allPermissions = RbacService::PERMISSIONS;
        $this->allRoles = DB::table('feature_access')->pluck('role')->toArray();

        foreach ($this->allFeatures as $feat) {
            foreach ($this->allRoles as $role) {
                $row = DB::table('feature_access')->where('role', $role)->first();
                $features = $row ? json_decode($row->features, true) : [];
                $this->featureMatrix[$role][$feat] = $features[$feat] ?? false;
            }
        }

        foreach ($this->allPermissions as $perm) {
            foreach ($this->allRoles as $role) {
                $row = DB::table('data_access')->where('role', $role)->first();
                $perms = $row ? json_decode($row->permissions, true) : [];
                $this->dataMatrix[$role][$perm] = $perms[$perm] ?? false;
            }
        }
    }

    public function saveGeneral(): void
    {
        DB::table('settings')->where('id', 1)->update([
            'agency_name' => $this->agencyName,
            'agency_email' => $this->agencyEmail ?: null,
            'agency_phone' => $this->agencyPhone ?: null,
            'currency' => $this->currency,
            'brand_color' => $this->brandColor,
            'file_retention_days' => $this->fileRetentionDays,
            'base_salary_default' => $this->baseSalaryDefault,
            'overtime_rate_default' => $this->overtimeRateDefault,
            'paid_leaves_per_year' => $this->paidLeavesPerYear,
            'working_days_per_month' => $this->workingDaysPerMonth,
            'daily_wage_divisor' => $this->dailyWageDivisor,
            'updated_at' => now(),
        ]);
        $this->dispatch('toast', message: 'General settings saved', type: 'success');
    }

    public function saveWorkingHours(): void
    {
        foreach ($this->workingHours as $day => $data) {
            DB::table('working_hours')->updateOrInsert(
                ['day' => $day],
                ['start' => $data['start'], 'end' => $data['end'], 'active' => $data['active'], 'updated_at' => now()]
            );
        }
        $this->dispatch('toast', message: 'Working hours saved', type: 'success');
    }

    public function toggleFeature(string $role, string $feature): void
    {
        abort_unless(Auth::user()->role === 'super-admin', 403);
        $this->featureMatrix[$role][$feature] = !$this->featureMatrix[$role][$feature];
        DB::table('feature_access')->where('role', $role)->update([
            'features' => json_encode($this->featureMatrix[$role]),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Cache::store('array')->forget("features:{$role}");
    }

    public function togglePerm(string $role, string $perm): void
    {
        abort_unless(Auth::user()->role === 'super-admin', 403);
        $this->dataMatrix[$role][$perm] = !$this->dataMatrix[$role][$perm];
    }

    public function saveDataAccess(): void
    {
        abort_unless(Auth::user()->role === 'super-admin', 403);
        foreach ($this->allRoles as $role) {
            DB::table('data_access')->where('role', $role)->update([
                'permissions' => json_encode($this->dataMatrix[$role] ?? []),
                'updated_at' => now(),
            ]);
            \Illuminate\Support\Facades\Cache::store('array')->forget("perms:{$role}");
        }
        $this->dispatch('toast', message: 'Data access permissions saved', type: 'success');
    }

    public function openRoleForm(?int $id = null): void
    {
        $this->formRoleFeatures = array_fill_keys(RbacService::FEATURES, false);
        $this->formRolePerms = array_fill_keys(RbacService::PERMISSIONS, false);

        if ($id) {
            $r = DB::table('custom_roles')->where('id', $id)->first();
            if ($r) {
                $this->editingRoleId = $id;
                $this->formRoleName = $r->name;
                $this->formRoleDesc = $r->description ?? '';
                // Load existing feature access
                $fa = DB::table('feature_access')->where('role', $r->role_key)->first();
                if ($fa) {
                    $features = json_decode($fa->features, true) ?? [];
                    foreach (RbacService::FEATURES as $f) {
                        $this->formRoleFeatures[$f] = $features[$f] ?? false;
                    }
                }
                // Load existing data access
                $da = DB::table('data_access')->where('role', $r->role_key)->first();
                if ($da) {
                    $perms = json_decode($da->permissions, true) ?? [];
                    foreach (RbacService::PERMISSIONS as $p) {
                        $this->formRolePerms[$p] = $perms[$p] ?? false;
                    }
                }
            }
        } else {
            $this->editingRoleId = 0;
            $this->formRoleName = '';
            $this->formRoleDesc = '';
        }
        $this->showRoleForm = true;
    }

    public function saveRole(): void
    {
        $this->validate(['formRoleName' => 'required|string|max:255']);
        $key = \Illuminate\Support\Str::slug($this->formRoleName);

        if ($this->editingRoleId) {
            DB::table('custom_roles')->where('id', $this->editingRoleId)->update([
                'name' => $this->formRoleName,
                'description' => $this->formRoleDesc ?: null,
                'updated_at' => now(),
            ]);
            // Get the role_key for this custom role
            $role = DB::table('custom_roles')->where('id', $this->editingRoleId)->first();
            $roleKey = $role->role_key;
        } else {
            $exists = DB::table('custom_roles')->where('role_key', $key)->exists();
            if ($exists) {
                $this->dispatch('toast', message: 'Role already exists', type: 'error');
                return;
            }
            DB::table('custom_roles')->insert([
                'role_key' => $key,
                'name' => $this->formRoleName,
                'description' => $this->formRoleDesc ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $roleKey = $key;
        }

        // Save feature access
        DB::table('feature_access')->updateOrInsert(
            ['role' => $roleKey],
            ['features' => json_encode($this->formRoleFeatures), 'updated_at' => now(), 'created_at' => now()]
        );
        \Illuminate\Support\Facades\Cache::store('array')->forget("features:{$roleKey}");

        // Save data access
        DB::table('data_access')->updateOrInsert(
            ['role' => $roleKey],
            ['permissions' => json_encode($this->formRolePerms), 'updated_at' => now(), 'created_at' => now()]
        );
        \Illuminate\Support\Facades\Cache::store('array')->forget("perms:{$roleKey}");

        $this->showRoleForm = false;
        $this->allRoles = DB::table('feature_access')->pluck('role')->toArray();
        $this->dispatch('toast', message: 'Role saved with access permissions', type: 'success');
    }

    public function deleteRole(int $id): void
    {
        $role = DB::table('custom_roles')->where('id', $id)->first();
        if ($role) {
            $memberCount = DB::table('users')->where('role', $role->role_key)->count();
            if ($memberCount > 0) {
                $this->dispatch('toast', message: 'Cannot delete role with members', type: 'error');
                return;
            }
            CustomRole::findOrFail($id)->delete();
            DB::table('feature_access')->where('role', $role->role_key)->delete();
            DB::table('data_access')->where('role', $role->role_key)->delete();
            $this->allRoles = DB::table('feature_access')->pluck('role')->toArray();
            $this->dispatch('toast', message: 'Role deleted', type: 'success');
        }
    }

    #[Computed]
    public function customRoles() { return DB::table('custom_roles')->get(); }

    #[Computed]
    public function lastBackup(): ?string
    {
        $s = DB::table('settings')->where('id', 1)->first();
        return $s?->last_backup_reminder;
    }

    public function saveBackupSettings(): void
    {
        DB::table('settings')->where('id', 1)->update([
            'backup_reminder_days' => $this->backupIntervalDays,
            'updated_at' => now(),
        ]);
        $this->dispatch('toast', message: 'Backup settings saved', type: 'success');
    }

    public function forceReminderNow(): void
    {
        DB::table('settings')->where('id', 1)->update([
            'last_backup_reminder' => now(),
            'updated_at' => now(),
        ]);
        $this->dispatch('toast', message: 'Backup reminder reset', type: 'success');
    }

    public function handleImport(): void
    {
        abort_unless(in_array(Auth::user()->role, ['super-admin', 'admin']), 403);
        $this->validate([
            'importFile' => 'required|file|mimes:json,txt|max:51200',
        ]);

        try {
            $payload = json_decode(
                file_get_contents($this->importFile->getRealPath()),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (! is_array($payload)) {
                throw new \RuntimeException('Backup file is not a valid JSON object');
            }
            app(\App\Services\DataBackupService::class)->import($payload);
            app(\App\Services\ActivityLogger::class)->record(Auth::user(), 'Imported data backup');
            $this->reset('importFile');
            $this->dispatch('toast', message: 'Backup imported successfully. Please reload.', type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Import failed: '.$e->getMessage(), type: 'error');
        }
    }

    public function openClearConfirm(): void
    {
        abort_unless(Auth::user()->role === 'super-admin', 403);
        $this->showClearConfirm = true;
    }

    public function clearAllData(): void
    {
        abort_unless(Auth::user()->role === 'super-admin', 403);

        $tables = ['complaint_replies','complaints','approval_comments','approvals',
            'task_comments','tasks','contents','workflows','workflow_stages',
            'invoice_payments','invoices','files','file_expiries','folders',
            'leaves','expenses','salaries','overtime_logs',
            'activity_logs','notifications','notification_rules',
            'clients','client_accounts','departments','custom_roles',
            'feature_access','data_access','working_hours','packages'];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }

        DB::table('settings')->where('id', 1)->update([
            'last_backup_reminder' => now(),
            'updated_at' => now(),
        ]);

        $this->showClearConfirm = false;
        $this->dispatch('toast', message: 'All data cleared. Please re-run seeders.', type: 'success');
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900">Settings</h1>
                <p class="text-sm text-gray-500 mt-1">Configure agency settings and permissions</p>
            </div>

            <div class="flex flex-col lg:flex-row gap-6">
                {{-- Sidebar --}}
                <div class="lg:w-48 flex-shrink-0">
                    <div class="bg-white rounded-2xl border border-gray-100 p-2 flex lg:flex-col gap-1 overflow-x-auto">
                        <button wire:click="$set('activeTab', 'general')" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $activeTab === 'general' ? 'bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]' : 'text-gray-600 hover:bg-gray-50' }}"><i class="fas fa-cog"></i> General</button>
                        <button wire:click="$set('activeTab', 'working-hours')" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $activeTab === 'working-hours' ? 'bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]' : 'text-gray-600 hover:bg-gray-50' }}"><i class="fas fa-clock"></i> Working Hours</button>
                        @if(Auth::user()->role === 'super-admin')
                        <button wire:click="$set('activeTab', 'feature-access')" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $activeTab === 'feature-access' ? 'bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]' : 'text-gray-600 hover:bg-gray-50' }}"><i class="fas fa-key"></i> Feature Access</button>
                        <button wire:click="$set('activeTab', 'data-access')" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $activeTab === 'data-access' ? 'bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]' : 'text-gray-600 hover:bg-gray-50' }}"><i class="fas fa-database"></i> Data Access</button>
                        @endif
                        <button wire:click="$set('activeTab', 'backup')" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $activeTab === 'backup' ? 'bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]' : 'text-gray-600 hover:bg-gray-50' }}"><i class="fas fa-download"></i> Backup</button>
                    </div>
                </div>

                {{-- Content --}}
                <div class="flex-1 min-w-0">

                    {{-- General --}}
                    @if($activeTab === 'general')
                        <div class="bg-white rounded-2xl border border-gray-100 p-6 space-y-5">
                            <h2 class="font-bold text-lg">General Settings</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label class="form-label">Agency Name</label><input type="text" wire:model="agencyName" class="form-input"></div>
                                <div><label class="form-label">Agency Email</label><input type="email" wire:model="agencyEmail" class="form-input"></div>
                                <div><label class="form-label">Agency Phone</label><input type="text" wire:model="agencyPhone" class="form-input"></div>
                                <div>
                                    <label class="form-label">Currency</label>
                                    <select wire:model="currency" class="form-select"><option value="NPR">NPR (रु)</option><option value="INR">INR (₹)</option><option value="USD">USD ($)</option></select>
                                </div>
                                <div>
                                    <label class="form-label">Brand Color</label>
                                    <div class="flex items-center gap-3">
                                        <input type="color" wire:model="brandColor" class="w-10 h-10 rounded-lg border cursor-pointer">
                                        <div class="flex gap-1">
                                            @foreach(['#4f46e5','#059669','#dc2626','#d97706','#7c3aed','#0891b2','#be185d','#334155'] as $c)
                                                <button wire:click="$set('brandColor', '{{ $c }}')" class="w-6 h-6 rounded-full border-2 {{ $brandColor === $c ? 'border-gray-900' : 'border-transparent' }}" style="background:{{ $c }}"></button>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div><label class="form-label">File Retention Days</label><input type="number" wire:model="fileRetentionDays" class="form-input" min="1"></div>
                                <div><label class="form-label">Base Salary Default</label><input type="number" wire:model="baseSalaryDefault" class="form-input" step="100" min="0"></div>
                                <div><label class="form-label">Overtime Rate Default</label><input type="number" wire:model="overtimeRateDefault" class="form-input" step="10" min="0"></div>
                                <div class="col-span-2 border-t border-gray-100 pt-4 mt-2">
                                    <h3 class="text-sm font-bold text-gray-700 mb-3"><i class="fas fa-calendar-check mr-1 text-purple-500"></i> Leave & Salary Settings</h3>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div><label class="form-label">Paid Leaves / Year</label><input type="number" wire:model="paidLeavesPerYear" class="form-input" min="0" max="365"></div>
                                        <div><label class="form-label">Working Days / Month</label><input type="number" wire:model="workingDaysPerMonth" class="form-input" min="1" max="31"></div>
                                        <div><label class="form-label">Daily Wage Divisor</label><input type="number" wire:model="dailyWageDivisor" class="form-input" step="0.01" min="1" max="31"></div>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-2">Paid leaves after exhaustion become unpaid (salary deduction). Daily wage = Base salary ÷ Divisor.</p>
                                </div>
                            </div>
                            <div class="flex justify-end"><button wire:click="saveGeneral" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveGeneral"><span wire:loading.remove wire:target="saveGeneral">Save Changes</span><span wire:loading wire:target="saveGeneral" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button></div>
                        </div>
                    @endif

                    {{-- Working Hours --}}
                    @if($activeTab === 'working-hours')
                        <div class="bg-white rounded-2xl border border-gray-100 p-6 space-y-5">
                            <h2 class="font-bold text-lg">Working Hours</h2>
                            <div class="space-y-2">
                                @foreach(['mon','tue','wed','thu','fri','sat','sun'] as $day)
                                    <div class="flex items-center gap-4 p-3 rounded-xl {{ $workingHours[$day]['active'] ? 'bg-white border border-gray-100' : 'bg-gray-50' }}">
                                        <button wire:click="$set('workingHours.{{ $day }}.active', {{ $workingHours[$day]['active'] ? 'false' : 'true' }})" class="toggle-switch {{ $workingHours[$day]['active'] ? 'on' : '' }}"></button>
                                        <span class="w-12 text-sm font-semibold capitalize">{{ $day }}</span>
                                        @if($workingHours[$day]['active'])
                                            <input type="time" wire:model="workingHours.{{ $day }}.start" class="form-input w-32">
                                            <span class="text-gray-400">to</span>
                                            <input type="time" wire:model="workingHours.{{ $day }}.end" class="form-input w-32">
                                        @else
                                            <span class="text-sm text-gray-400">Closed</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex justify-end"><button wire:click="saveWorkingHours" class="btn btn-primary">Save Hours</button></div>
                        </div>
                    @endif

                    {{-- Feature Access --}}
                    @if($activeTab === 'feature-access')
                        <div class="bg-white rounded-2xl border border-gray-100 p-6 space-y-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="font-bold text-lg">Feature Access Control</h2>
                                    <p class="text-sm text-gray-500 mt-1">Toggle which features each role can access in the sidebar</p>
                                </div>
                                <button wire:click="openRoleForm" class="btn btn-secondary btn-sm"><i class="fas fa-plus text-sm"></i> Manage Roles</button>
                            </div>

                            @php
                                $featureLabels = [
                                    'dashboard' => ['label' => 'Dashboard', 'icon' => 'fa-th-large', 'desc' => 'Main dashboard with metrics and charts'],
                                    'clients' => ['label' => 'Clients', 'icon' => 'fa-users', 'desc' => 'Manage client profiles and data'],
                                    'contentPlanner' => ['label' => 'Content Planner', 'icon' => 'fa-calendar-alt', 'desc' => 'Plan and schedule content'],
                                    'workflow' => ['label' => 'Workflow', 'icon' => 'fa-columns', 'desc' => 'Kanban-style workflow boards'],
                                    'tasks' => ['label' => 'Tasks & Shoots', 'icon' => 'fa-tasks', 'desc' => 'Task management and shoot scheduling'],
                                    'approvals' => ['label' => 'Approvals', 'icon' => 'fa-check-double', 'desc' => 'Content approval workflows'],
                                    'files' => ['label' => 'Files & Media', 'icon' => 'fa-folder-open', 'desc' => 'File storage and media management'],
                                    'reports' => ['label' => 'Reports & Finance', 'icon' => 'fa-chart-bar', 'desc' => 'Financial reports and invoices'],
                                    'leaves' => ['label' => 'Leaves', 'icon' => 'fa-calendar-minus', 'desc' => 'Leave requests and management'],
                                    'expenses' => ['label' => 'Expenses', 'icon' => 'fa-receipt', 'desc' => 'Expense tracking and approvals'],
                                    'salary' => ['label' => 'Salary', 'icon' => 'fa-money-bill-wave', 'desc' => 'Salary management and payments'],
                                    'overtime' => ['label' => 'Overtime', 'icon' => 'fa-clock', 'desc' => 'Overtime tracking and approvals'],
                                    'team' => ['label' => 'Team', 'icon' => 'fa-users-cog', 'desc' => 'Team member management'],
                                    'settings' => ['label' => 'Settings', 'icon' => 'fa-cog', 'desc' => 'System configuration and preferences'],
                                    'userGuide' => ['label' => 'User Guide', 'icon' => 'fa-book', 'desc' => 'Help documentation and guides'],
                                    'complaints' => ['label' => 'Complaints', 'icon' => 'fa-exclamation-circle', 'desc' => 'Client complaint tracking'],
                                    'clientPortal' => ['label' => 'Client Portal', 'icon' => 'fa-globe', 'desc' => 'Client-facing portal access'],
                                ];
                            @endphp

                            <div class="overflow-x-auto">
                                <table class="data-table w-full text-sm">
                                    <thead>
                                        <tr>
                                            <th class="w-56">Feature</th>
                                            @foreach($allRoles as $role)
                                                <th class="text-center capitalize min-w-[80px]">{{ str_replace('-', ' ', $role) }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($allFeatures as $feat)
                                            @php $info = $featureLabels[$feat] ?? ['label' => ucfirst($feat), 'icon' => 'fa-puzzle-piece', 'desc' => '']; @endphp
                                            <tr class="hover:bg-gray-50">
                                                <td>
                                                    <div class="flex items-center gap-3">
                                                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
                                                            <i class="fas {{ $info['icon'] }} text-xs"></i>
                                                        </div>
                                                        <div>
                                                            <div class="font-medium text-gray-900">{{ $info['label'] }}</div>
                                                            @if($info['desc'])
                                                                <div class="text-xs text-gray-400">{{ $info['desc'] }}</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </td>
                                                @foreach($allRoles as $role)
                                                    <td class="text-center">
                                                        @if($role === 'super-admin')
                                                            <span class="inline-flex items-center gap-1 text-green-600 text-xs font-medium">
                                                                <i class="fas fa-lock"></i> Always
                                                            </span>
                                                        @else
                                                            <button wire:click="toggleFeature('{{ $role }}', '{{ $feat }}')" class="toggle-switch {{ ($featureMatrix[$role][$feat] ?? false) ? 'on' : '' }}"></button>
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4">
                                <div class="flex items-start gap-3">
                                    <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                                    <div class="text-sm text-blue-700">
                                        <p class="font-medium">How it works</p>
                                        <ul class="mt-1 space-y-1 text-xs">
                                            <li>• <strong>Super Admin</strong> always has access to all features (cannot be changed)</li>
                                            <li>• Toggle switches control sidebar visibility for each role</li>
                                            <li>• Changes take effect immediately for users with that role</li>
                                            <li>• Use "Manage Roles" to create custom roles</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Data Access --}}
                    @if($activeTab === 'data-access')
                        <div class="bg-white rounded-2xl border border-gray-100 p-6 space-y-5">
                            <div>
                                <h2 class="font-bold text-lg">Data Access Permissions</h2>
                                <p class="text-sm text-gray-500 mt-1">Control what data each role can see and modify</p>
                            </div>

                            @php
                                $permLabels = [
                                    'seeAllTasks' => ['label' => 'See All Tasks', 'desc' => 'View tasks across all team members'],
                                    'seeAllWorkflow' => ['label' => 'See All Workflow', 'desc' => 'View workflow items from all clients'],
                                    'seeAllPerformance' => ['label' => 'See All Performance', 'desc' => 'Access performance metrics for all staff'],
                                    'seeAllActivity' => ['label' => 'See All Activity', 'desc' => 'View activity logs across the system'],
                                    'canAddTasks' => ['label' => 'Can Add Tasks', 'desc' => 'Create new tasks and assignments'],
                                    'canMoveWorkflow' => ['label' => 'Can Move Workflow', 'desc' => 'Move items between workflow stages'],
                                    'canEditWorkflow' => ['label' => 'Can Edit Workflow', 'desc' => 'Edit workflow item details'],
                                ];
                            @endphp

                            <div class="overflow-x-auto">
                                <table class="data-table w-full text-sm">
                                    <thead>
                                        <tr>
                                            <th class="w-56">Permission</th>
                                            @foreach($allRoles as $role)
                                                <th class="text-center capitalize min-w-[80px]">{{ str_replace('-', ' ', $role) }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($allPermissions as $perm)
                                            @php $info = $permLabels[$perm] ?? ['label' => ucfirst($perm), 'desc' => '']; @endphp
                                            <tr class="hover:bg-gray-50">
                                                <td>
                                                    <div class="flex items-center gap-3">
                                                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
                                                            <i class="fas fa-key text-xs"></i>
                                                        </div>
                                                        <div>
                                                            <div class="font-medium text-gray-900">{{ $info['label'] }}</div>
                                                            @if($info['desc'])
                                                                <div class="text-xs text-gray-400">{{ $info['desc'] }}</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </td>
                                                @foreach($allRoles as $role)
                                                    <td class="text-center">
                                                        @if($role === 'super-admin')
                                                            <span class="inline-flex items-center gap-1 text-green-600 text-xs font-medium">
                                                                <i class="fas fa-lock"></i> Always
                                                            </span>
                                                        @else
                                                            <button wire:click="togglePerm('{{ $role }}', '{{ $perm }}')" class="toggle-switch {{ ($dataMatrix[$role][$perm] ?? false) ? 'on' : '' }}"></button>
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="flex justify-end"><button wire:click="saveDataAccess" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveDataAccess"><span wire:loading.remove wire:target="saveDataAccess">Save Permissions</span><span wire:loading wire:target="saveDataAccess" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button></div>
                        </div>
                    @endif

                    {{-- Backup --}}
                    @if($activeTab === 'backup')
                        <div class="bg-white rounded-2xl border border-gray-100 p-6 space-y-6">
                            <div>
                                <h2 class="font-bold text-lg">Backup & Data Management</h2>
                                <p class="text-sm text-gray-500 mt-1">Export, import, or clear your agency data</p>
                            </div>

                            {{-- Backup Settings --}}
                            <div class="rounded-xl border border-gray-100 p-4 space-y-4">
                                <h3 class="font-semibold text-sm">Backup Reminder Settings</h3>
                                <div class="flex items-end gap-4">
                                    <div class="flex-1">
                                        <label class="form-label">Reminder Interval (days)</label>
                                        <select wire:model="backupIntervalDays" class="form-select">
                                            <option value="3">Every 3 days</option>
                                            <option value="7">Every 7 days</option>
                                            <option value="14">Every 14 days</option>
                                            <option value="30">Every 30 days</option>
                                        </select>
                                    </div>
                                    <button wire:click="saveBackupSettings" class="btn btn-primary btn-sm">Save</button>
                                    <button wire:click="forceReminderNow" class="btn btn-secondary btn-sm"><i class="fas fa-redo text-xs mr-1"></i> Force Reminder Now</button>
                                </div>
                                <p class="text-xs text-gray-400">Last backup reminder: {{ $this->lastBackup ? \Carbon\Carbon::parse($this->lastBackup)->diffForHumans() : 'Never' }}</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                {{-- Export --}}
                                <div class="rounded-xl border border-gray-100 p-4 space-y-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-green-50 text-green-600">
                                            <i class="fas fa-download"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-semibold text-sm">Export All Data</h3>
                                            <p class="text-xs text-gray-500">Download JSON backup of all tables</p>
                                        </div>
                                    </div>
                                    <a href="{{ url('settings/export') }}" class="btn btn-secondary btn-sm w-full justify-center"><i class="fas fa-file-download mr-2"></i> Download JSON Backup</a>
                                </div>

                                {{-- Import --}}
                                <div class="rounded-xl border border-gray-100 p-4 space-y-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                                            <i class="fas fa-upload"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-semibold text-sm">Import Data</h3>
                                            <p class="text-xs text-gray-500">Restore from a JSON backup file</p>
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <input type="file" wire:model="importFile" accept=".json" class="form-input text-xs flex-1">
                                        <button wire:click="handleImport" class="btn btn-primary btn-sm"><i class="fas fa-file-upload mr-1"></i> Import</button>
                                    </div>
                                </div>
                            </div>

                            {{-- Clear All Data --}}
                            @if(Auth::user()->role === 'super-admin')
                            <div class="rounded-xl border-2 border-red-200 bg-red-50 p-4 space-y-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-600">
                                        <i class="fas fa-trash-alt"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-sm text-red-800">Clear All Data</h3>
                                        <p class="text-xs text-red-600">Super Admin only. This will wipe all data and re-seed defaults.</p>
                                    </div>
                                </div>
                                <button wire:click="openClearConfirm" class="btn btn-danger btn-sm"><i class="fas fa-exclamation-triangle mr-1"></i> Clear All Data</button>
                            </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Role Manager Modal --}}
            @if($showRoleForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showRoleForm', false)" x-on:keydown.escape.window="$wire.set('showRoleForm', false)">
                    <div class="modal-box w-full max-w-lg mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">Manage Custom Roles</h3>
                            <button wire:click="$set('showRoleForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            {{-- Role List --}}
                            @if($this->customRoles->count())
                                <div class="space-y-2">
                                    @foreach($this->customRoles as $role)
                                        <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                                            <div>
                                                <div class="font-medium text-sm">{{ $role->name }}</div>
                                                <div class="text-xs text-gray-400">{{ $role->description ?? 'No description' }}</div>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <button wire:click="openRoleForm({{ $role->id }})" class="btn btn-icon btn-ghost" title="Edit"><i class="fas fa-pen text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Role?', message: 'Users currently assigned to this role will need to be reassigned.', type: 'danger', action: 'deleteRole', params: [{{ $role->id }}] })" class="btn btn-icon btn-ghost" aria-label="Delete role" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-sm text-gray-400 text-center py-2">No custom roles yet</p>
                            @endif

                            {{-- Add/Edit Form --}}
                            <div class="border-t pt-4">
                                <h4 class="font-semibold text-sm mb-3">{{ $editingRoleId ? 'Edit' : 'Add' }} Role</h4>
                                <div class="space-y-3">
                                    <div><label class="form-label">Role Name</label><input type="text" wire:model="formRoleName" class="form-input" placeholder="e.g. Senior Designer"><span wire:error="formRoleName" class="text-red-500 text-xs mt-1 block"></span></div>
                                    <div><label class="form-label">Description</label><textarea wire:model="formRoleDesc" class="form-input" rows="2"></textarea></div>

                                    {{-- Feature Access --}}
                                    <div>
                                        <label class="form-label font-semibold text-gray-700"><i class="fas fa-puzzle-piece mr-1 text-blue-500"></i> Feature Access</label>
                                        <div class="grid grid-cols-2 gap-1 mt-1">
                                            @php
                                                $featureLabels = [
                                                    'dashboard' => 'Dashboard', 'clients' => 'Clients', 'packages' => 'Packages',
                                                    'contentPlanner' => 'Content Planner', 'workflow' => 'Workflow', 'tasks' => 'Tasks & Shoots',
                                                    'approvals' => 'Approvals', 'files' => 'Files & Media', 'reports' => 'Reports & Finance',
                                                    'leaves' => 'Leaves', 'expenses' => 'Expenses', 'salary' => 'Salary',
                                                    'overtime' => 'Overtime', 'team' => 'Team', 'settings' => 'Settings',
                                                    'userGuide' => 'User Guide', 'complaints' => 'Complaints', 'clientPortal' => 'Client Portal',
                                                ];
                                            @endphp
                                            @foreach($featureLabels as $feat => $label)
                                                <label class="flex items-center gap-2 text-xs p-1.5 rounded-lg hover:bg-gray-50 cursor-pointer {{ $formRoleFeatures[$feat] ?? false ? 'bg-blue-50' : '' }}">
                                                    <input type="checkbox" wire:model.live="formRoleFeatures.{{ $feat }}" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    {{-- Data Permissions --}}
                                    <div>
                                        <label class="form-label font-semibold text-gray-700"><i class="fas fa-database mr-1 text-purple-500"></i> Data Permissions</label>
                                        <div class="grid grid-cols-2 gap-1 mt-1">
                                            @php
                                                $permLabels = [
                                                    'seeAllTasks' => 'See All Tasks', 'seeAllWorkflow' => 'See All Workflow',
                                                    'seeAllPerformance' => 'See All Performance', 'seeAllActivity' => 'See All Activity',
                                                    'canAddTasks' => 'Can Add Tasks', 'canMoveWorkflow' => 'Can Move Workflow',
                                                    'canEditWorkflow' => 'Can Edit Workflow',
                                                ];
                                            @endphp
                                            @foreach($permLabels as $perm => $label)
                                                <label class="flex items-center gap-2 text-xs p-1.5 rounded-lg hover:bg-gray-50 cursor-pointer {{ $formRolePerms[$perm] ?? false ? 'bg-purple-50' : '' }}">
                                                    <input type="checkbox" wire:model.live="formRolePerms.{{ $perm }}" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-3.5 h-3.5">
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showRoleForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveRole" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveRole"><span wire:loading.remove wire:target="saveRole">Save</span><span wire:loading wire:target="saveRole" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Clear All Data Confirmation Modal --}}
            @if($showClearConfirm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showClearConfirm', false)" x-on:keydown.escape.window="$wire.set('showClearConfirm', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Clear All Data</h3>
                            <button wire:click="$set('showClearConfirm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="flex items-center justify-center">
                                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
                                    <i class="fas fa-trash-alt text-2xl text-red-600"></i>
                                </div>
                            </div>
                            <div class="text-center">
                                <h4 class="font-bold text-gray-900">This action cannot be undone!</h4>
                                <p class="text-sm text-gray-500 mt-2">This will permanently delete all data including:</p>
                                <ul class="text-xs text-gray-500 mt-2 space-y-1 text-left max-h-40 overflow-y-auto">
                                    <li>• All clients and client accounts</li>
                                    <li>• All content, workflows, tasks, and approvals</li>
                                    <li>• All files, invoices, payments, and expenses</li>
                                    <li>• All leaves, salaries, overtime logs, and complaints</li>
                                    <li>• All custom roles, feature/data access settings</li>
                                    <li>• All activity logs and notifications</li>
                                </ul>
                                <p class="text-xs text-red-600 mt-3 font-medium">You will need to re-run <code>php artisan db:seed</code> to restore demo data.</p>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showClearConfirm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="clearAllData" class="btn btn-danger"><i class="fas fa-trash-alt mr-1"></i> Yes, Clear Everything</button>
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
