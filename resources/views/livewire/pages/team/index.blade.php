<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\RbacService;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $activeTab = 'members';
    public string $search = '';
    public string $roleFilter = '';
    public string $statusFilter = '';
    public bool $showMemberForm = false;
    public bool $showDeptForm = false;
    public int $editingMemberId = 0;
    public int $editingDeptId = 0;

    // Member form
    public string $formName = '';
    public string $formEmail = '';
    public string $formPhone = '';
    public string $formRole = 'editor';
    public int $formDeptId = 0;
    public string $formPassword = '';
    public string $formStatus = 'active';
    public string $formJoinDate = '';
    public float $formBaseSalary = 0;

    // Department form
    public string $formDeptName = '';
    public string $formDeptDesc = '';

    // Role form
    public bool $showRoleForm = false;
    public int $editingRoleId = 0;
    public string $formRoleName = '';
    public string $formRoleDesc = '';
    public array $formRoleFeatures = [];
    public array $formRolePerms = [];

    public function mount(): void
    {
        $this->formJoinDate = now()->format('Y-m-d');
    }

    public function isSuperAdmin(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        return $user->role === 'super-admin';
    }

    public function canEdit(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        return in_array($user->role, ['super-admin', 'admin', 'manager']);
    }

    public function getRoleCounts(): array
    {
        $counts = DB::table('users')->selectRaw('role, COUNT(*) as cnt')->groupBy('role')->pluck('cnt', 'role')->toArray();
        // Include custom roles
        $customRoles = DB::table('custom_roles')->get();
        foreach ($customRoles as $cr) {
            if (!isset($counts[$cr->role_key])) {
                $counts[$cr->role_key] = 0;
            }
        }
        return $counts;
    }

    public function getMembers()
    {
        $q = DB::table('users')
            ->leftJoin('departments', 'users.department_id', '=', 'departments.id');

        if ($this->search) {
            $q->where(function ($sub) {
                $sub->where('users.name', 'like', "%{$this->search}%")
                    ->orWhere('users.email', 'like', "%{$this->search}%");
            });
        }
        if ($this->roleFilter) $q->where('users.role', $this->roleFilter);
        if ($this->statusFilter) $q->where('users.status', $this->statusFilter);

        return $q->select('users.*', 'departments.name as dept_name')
            ->orderBy('users.name')
            ->paginate(50);
    }

    public function getDepartments()
    {
        return DB::table('departments')
            ->leftJoin('users', 'departments.id', '=', 'users.department_id')
            ->select('departments.*', DB::raw('COUNT(users.id) as member_count'))
            ->groupBy('departments.id', 'departments.name', 'departments.description', 'departments.created_at', 'departments.updated_at')
            ->orderBy('departments.name')
            ->get();
    }

    public function openMemberForm(?int $id = null): void
    {
        if ($id) {
            $m = DB::table('users')->where('id', $id)->first();
            if ($m) {
                $this->editingMemberId = $id;
                $this->formName = $m->name;
                $this->formEmail = $m->email;
                $this->formPhone = $m->phone ?? '';
                $this->formRole = $m->role;
                $this->formDeptId = $m->department_id ?? 0;
                $this->formStatus = $m->status ?? 'active';
                $this->formJoinDate = $m->join_date ? date('Y-m-d', strtotime($m->join_date)) : '';
                $this->formPassword = '';
                // Load current month base salary
                $salary = DB::table('salaries')
                    ->where('member_id', $id)
                    ->where('month', now()->month)
                    ->where('year', now()->year)
                    ->first();
                $this->formBaseSalary = $salary ? (float) $salary->base_salary : (float) DB::table('settings')->where('id', 1)->value('base_salary_default');
            }
        } else {
            $this->editingMemberId = 0;
            $this->formName = '';
            $this->formEmail = '';
            $this->formPhone = '';
            $this->formRole = 'editor';
            $this->formDeptId = 0;
            $this->formPassword = '';
            $this->formStatus = 'active';
            $this->formJoinDate = now()->format('Y-m-d');
            $this->formBaseSalary = (float) DB::table('settings')->where('id', 1)->value('base_salary_default');
        }
        $this->showMemberForm = true;
    }

    public function saveMember(): void
    {
        $rules = [
            'formName' => 'required|string|max:255',
            'formEmail' => 'required|email|max:255',
            'formRole' => 'required|string',
            'formStatus' => 'required|in:active,inactive',
            'formBaseSalary' => 'required|numeric|min:0',
        ];
        if (!$this->editingMemberId) {
            $rules['formPassword'] = 'required|string|min:8';
        }
        $this->validate($rules);

        // Super-admin protection
        if ($this->editingMemberId) {
            $existing = DB::table('users')->where('id', $this->editingMemberId)->first();
            if ($existing && $existing->role === 'super-admin' && !$this->isSuperAdmin()) {
                $this->dispatch('toast', message: 'Cannot edit super-admin', type: 'error');
                return;
            }
        }

        $data = [
            'name' => $this->formName,
            'email' => $this->formEmail,
            'phone' => $this->formPhone ?: null,
            'role' => $this->formRole,
            'department_id' => $this->formDeptId ?: null,
            'status' => $this->formStatus,
            'join_date' => $this->formJoinDate ?: null,
        ];

        if ($this->formPassword) {
            $data['password'] = Hash::make($this->formPassword);
        }

        if ($this->editingMemberId) {
            DB::table('users')->where('id', $this->editingMemberId)->update($data);
            $memberId = $this->editingMemberId;
        } else {
            $data['created_at'] = now();
            $data['updated_at'] = now();
            $memberId = DB::table('users')->insertGetId($data);
        }

        // Create or update salary record for current month
        $month = (int) now()->month;
        $year = (int) now()->year;
        $salaryExists = DB::table('salaries')
            ->where('member_id', $memberId)
            ->where('month', $month)
            ->where('year', $year)
            ->exists();

        if ($salaryExists) {
            DB::table('salaries')
                ->where('member_id', $memberId)
                ->where('month', $month)
                ->where('year', $year)
                ->update(['base_salary' => $this->formBaseSalary, 'updated_at' => now()]);
        } else {
            DB::table('salaries')->insert([
                'member_id' => $memberId,
                'month' => $month,
                'year' => $year,
                'base_salary' => $this->formBaseSalary,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Recalculate salary if calculator exists
        $salaryModel = \App\Models\Salary::where('member_id', $memberId)->where('month', $month)->where('year', $year)->first();
        if ($salaryModel) {
            app(\App\Services\SalaryCalculator::class)->recalc($salaryModel);
        }

        $this->showMemberForm = false;
        $this->dispatch('toast', message: 'Member saved', type: 'success');
    }

    public function deleteMember(int $id): void
    {
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) return;
        if ($user->role === 'super-admin') {
            $this->dispatch('toast', message: 'Cannot delete super-admin', type: 'error');
            return;
        }
        if ($id === Auth::id()) {
            $this->dispatch('toast', message: 'Cannot delete yourself', type: 'error');
            return;
        }
        DB::table('users')->where('id', $id)->delete();
        $this->dispatch('toast', message: 'Member deleted', type: 'success');
    }

    public function toggleStatus(int $id): void
    {
        if ($id === Auth::id()) return;
        $user = DB::table('users')->where('id', $id)->first();
        if ($user && $user->role !== 'super-admin') {
            $newStatus = $user->status === 'active' ? 'inactive' : 'active';
            DB::table('users')->where('id', $id)->update(['status' => $newStatus, 'updated_at' => now()]);
            $this->dispatch('toast', message: 'Status updated', type: 'success');
        }
    }

    public function openDeptForm(?int $id = null): void
    {
        if ($id) {
            $d = DB::table('departments')->where('id', $id)->first();
            if ($d) {
                $this->editingDeptId = $id;
                $this->formDeptName = $d->name;
                $this->formDeptDesc = $d->description ?? '';
            }
        } else {
            $this->editingDeptId = 0;
            $this->formDeptName = '';
            $this->formDeptDesc = '';
        }
        $this->showDeptForm = true;
    }

    public function saveDept(): void
    {
        $this->validate(['formDeptName' => 'required|string|max:255']);

        $data = [
            'name' => $this->formDeptName,
            'description' => $this->formDeptDesc ?: null,
        ];

        if ($this->editingDeptId) {
            DB::table('departments')->where('id', $this->editingDeptId)->update($data);
        } else {
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('departments')->insert($data);
        }

        $this->showDeptForm = false;
        $this->dispatch('toast', message: 'Department saved', type: 'success');
    }

    public function deleteDept(int $id): void
    {
        $memberCount = DB::table('users')->where('department_id', $id)->count();
        if ($memberCount > 0) {
            $this->dispatch('toast', message: 'Cannot delete department with members', type: 'error');
            return;
        }
        DB::table('departments')->where('id', $id)->delete();
        $this->dispatch('toast', message: 'Department deleted', type: 'success');
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
                $fa = DB::table('feature_access')->where('role', $r->role_key)->first();
                if ($fa) {
                    $features = json_decode($fa->features, true) ?? [];
                    foreach (RbacService::FEATURES as $f) {
                        $this->formRoleFeatures[$f] = $features[$f] ?? false;
                    }
                }
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

        DB::table('feature_access')->updateOrInsert(
            ['role' => $roleKey],
            ['features' => json_encode($this->formRoleFeatures), 'updated_at' => now(), 'created_at' => now()]
        );
        \Illuminate\Support\Facades\Cache::store('array')->forget("features:{$roleKey}");

        DB::table('data_access')->updateOrInsert(
            ['role' => $roleKey],
            ['permissions' => json_encode($this->formRolePerms), 'updated_at' => now(), 'created_at' => now()]
        );
        \Illuminate\Support\Facades\Cache::store('array')->forget("perms:{$roleKey}");

        $this->showRoleForm = false;
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
            DB::table('custom_roles')->where('id', $id)->delete();
            DB::table('feature_access')->where('role', $role->role_key)->delete();
            DB::table('data_access')->where('role', $role->role_key)->delete();
            \Illuminate\Support\Facades\Cache::store('array')->forget("features:{$role->role_key}");
            \Illuminate\Support\Facades\Cache::store('array')->forget("perms:{$role->role_key}");
            $this->dispatch('toast', message: 'Role deleted', type: 'success');
        }
    }

    #[Computed]
    public function customRoles() { return DB::table('custom_roles')->get(); }

    #[Computed]
    public function roleCounts(): array { return $this->getRoleCounts(); }

    #[Computed]
    public function members() { return $this->getMembers(); }

    #[Computed]
    public function departments() { return $this->getDepartments(); }

    #[Computed]
    public function allRoles(): array
    {
        $builtins = RbacService::BUILT_IN_ROLES;
        $custom = DB::table('custom_roles')->pluck('name', 'role_key')->toArray();
        return array_merge($builtins, $custom);
    }

    #[Computed]
    public function isSuperAdminUser(): bool { return $this->isSuperAdmin(); }

    #[Computed]
    public function canEditMember(): bool { return $this->canEdit(); }

    #[Computed]
    public function builtInRoles(): array { return RbacService::BUILT_IN_ROLES; }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Team</h1>
                    <p class="text-sm text-gray-500 mt-1">Manage team members and departments</p>
                </div>
                @if($this->canEditMember)
                    <button wire:click="openMemberForm" class="btn btn-primary"><i class="fas fa-plus text-sm"></i> Add Member</button>
                @endif
            </div>

            {{-- Skeleton loader --}}
            <div wire:loading.delay class="space-y-4 p-6">
                <div class="h-8 bg-gray-200 rounded animate-pulse w-1/3"></div>
                <div class="h-4 bg-gray-200 rounded animate-pulse w-2/3"></div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
                    <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
                    <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
                    <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
                </div>
                <div class="h-64 bg-gray-200 rounded-2xl animate-pulse"></div>
            </div>

            {{-- Role Count Cards --}}
            <div wire:loading.remove.delay class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
                @foreach($this->roleCounts as $role => $count)
                    <div class="stat-card text-center">
                        <div class="stat-value text-lg">{{ $count }}</div>
                        <div class="stat-label">{{ roleName($role) }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Tabs --}}
            <div class="tab-group">
                <button wire:click="$set('activeTab', 'members')" class="tab-btn {{ $activeTab === 'members' ? 'active' : '' }}">Members</button>
                <button wire:click="$set('activeTab', 'departments')" class="tab-btn {{ $activeTab === 'departments' ? 'active' : '' }}">Departments</button>
                <button wire:click="$set('activeTab', 'roles')" class="tab-btn {{ $activeTab === 'roles' ? 'active' : '' }}">Roles</button>
            </div>

            {{-- Members Tab --}}
            @if($activeTab === 'members')
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                    <div><label class="form-label">Search</label><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="search" wire:model.live.debounce.250ms="search" placeholder="Search name or email..." class="form-input pl-10 focus:ring-0"></div></div>
                    <div><label class="form-label">Role</label><select wire:model.live="roleFilter" class="form-select">
                        <option value="">All Roles</option>
                        @foreach($this->allRoles as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select></div>
                    <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select></div>
                </div>

                <div class="overflow-x-auto">
                    <table class="data-table w-full">
                        <thead><tr><th>Member</th><th>Role</th><th>Department</th><th>Phone</th><th>Joined</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            @forelse($this->members as $m)
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold">{{ strtoupper(substr($m->name, 0, 2)) }}</div>
                                            <div>
                                                <div class="font-medium text-sm">{{ $m->name }}</div>
                                                <div class="text-xs text-gray-400">{{ $m->email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge {{ 'role-' . $m->role }}">
                                            @if($m->role === 'super-admin') <i class="fas fa-crown text-[10px]"></i> @endif
                                            {{ roleName($m->role) }}
                                        </span>
                                    </td>
                                    <td class="text-sm">{{ $m->dept_name ?? '-' }}</td>
                                    <td class="text-sm">{{ $m->phone ?? '-' }}</td>
                                    <td class="text-sm">{{ $m->join_date ? fmtDate($m->join_date) : '-' }}</td>
                                    <td>
                                        @if($m->id !== Auth::id() && $m->role !== 'super-admin')
                                            <button wire:click="toggleStatus({{ $m->id }})" class="badge {{ $m->status === 'active' ? 'badge-active' : 'badge-inactive' }} cursor-pointer hover:opacity-80">
                                                {{ ucfirst($m->status ?? 'active') }}
                                            </button>
                                        @else
                                            <span class="badge {{ $m->status === 'active' ? 'badge-active' : 'badge-inactive' }}">{{ ucfirst($m->status ?? 'active') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($this->canEditMember)
                                            <div class="flex items-center gap-1">
                                                <button wire:click="openMemberForm({{ $m->id }})" class="btn btn-icon btn-ghost" title="Edit"><i class="fas fa-pen text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                                @if($m->role !== 'super-admin' && $m->id !== Auth::id())
                                                    <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Team Member?', message: 'This team member will lose access and be removed.', type: 'danger', action: 'deleteMember', params: [{{ $m->id }}] })" class="btn btn-icon btn-ghost" aria-label="Delete team member" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7">
                                    <div class="py-16 text-center">
                                        <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"><i class="fas fa-users text-2xl text-gray-300"></i></div>
                                        <p class="text-gray-500 font-medium text-sm">No members found</p>
                                        <p class="text-gray-400 text-xs mt-1">Add team members to get started</p>
                                    </div>
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->getMembers()->links() }}
            @endif

            {{-- Departments Tab --}}
            @if($activeTab === 'departments')
                <div class="flex justify-end">
                    @if($this->canEditMember)
                        <button wire:click="openDeptForm" class="btn btn-primary btn-sm"><i class="fas fa-plus text-sm"></i> Add Department</button>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table w-full">
                        <thead><tr><th>Department</th><th>Description</th><th>Members</th><th>Actions</th></tr></thead>
                        <tbody>
                            @forelse($this->departments as $d)
                                <tr>
                                    <td class="font-medium">{{ $d->name }}</td>
                                    <td class="text-sm text-gray-600">{{ $d->description ?? '-' }}</td>
                                    <td class="text-sm">{{ $d->member_count }}</td>
                                    <td>
                                        @if($this->canEditMember)
                                            <div class="flex items-center gap-1">
                                                <button wire:click="openDeptForm({{ $d->id }})" class="btn btn-icon btn-ghost" title="Edit"><i class="fas fa-pen text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Department?', message: 'Members currently in this department will need to be reassigned.', type: 'danger', action: 'deleteDept', params: [{{ $d->id }}] })" class="btn btn-icon btn-ghost" aria-label="Delete department" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4">
                                    <div class="py-16 text-center">
                                        <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"><i class="fas fa-sitemap text-2xl text-gray-300"></i></div>
                                        <p class="text-gray-500 font-medium text-sm">No departments found</p>
                                        <p class="text-gray-400 text-xs mt-1">Create departments to organize your team</p>
                                    </div>
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Roles Tab --}}
            @if($activeTab === 'roles')
                <div class="flex justify-between items-center">
                    <p class="text-sm text-gray-500">Built-in roles cannot be deleted. Create custom roles with specific feature and data access.</p>
                    @if($this->canEditMember)
                        <button wire:click="openRoleForm" class="btn btn-primary btn-sm"><i class="fas fa-plus text-sm"></i> Add Role</button>
                    @endif
                </div>

                {{-- Built-in Roles --}}
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Built-in Roles</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        @foreach($this->builtInRoles as $builtin)
                            <div class="bg-white rounded-xl border border-gray-100 p-3 text-center">
                                <div class="font-semibold text-sm text-gray-800">{{ roleName($builtin) }}</div>
                                <div class="text-xs text-gray-400 mt-0.5">{{ $builtin }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Custom Roles --}}
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Custom Roles</h3>
                    @if($this->customRoles->count())
                        <div class="space-y-2">
                            @foreach($this->customRoles as $role)
                                @php
                                    $fa = DB::table('feature_access')->where('role', $role->role_key)->first();
                                    $enabledFeatures = $fa ? array_keys(array_filter(json_decode($fa->features, true) ?? [])) : [];
                                    $memberCount = DB::table('users')->where('role', $role->role_key)->count();
                                @endphp
                                <div class="bg-white rounded-xl border border-gray-100 p-4 hover:shadow-sm transition-shadow">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-semibold text-sm text-gray-800">{{ $role->name }}</span>
                                                <span class="text-xs text-gray-400">{{ $role->role_key }}</span>
                                                @if($memberCount > 0)
                                                    <span class="badge badge-info text-[10px]">{{ $memberCount }} member{{ $memberCount > 1 ? 's' : '' }}</span>
                                                @endif
                                            </div>
                                            @if($role->description)
                                                <p class="text-xs text-gray-500 mt-0.5">{{ $role->description }}</p>
                                            @endif
                                            @if(count($enabledFeatures) > 0)
                                                <div class="flex flex-wrap gap-1 mt-2">
                                                    @foreach(array_slice($enabledFeatures, 0, 6) as $f)
                                                        <span class="text-[10px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-full">{{ $f }}</span>
                                                    @endforeach
                                                    @if(count($enabledFeatures) > 6)
                                                        <span class="text-[10px] text-gray-400">+{{ count($enabledFeatures) - 6 }} more</span>
                                                    @endif
                                                </div>
                                            @else
                                                <p class="text-[10px] text-gray-400 mt-1">No features enabled</p>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <button wire:click="openRoleForm({{ $role->id }})" class="btn btn-icon btn-ghost" title="Edit"><i class="fas fa-pen text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                            <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Role?', message: 'This role will be permanently removed. Members using it will need to be reassigned.', type: 'danger', action: 'deleteRole', params: [{{ $role->id }}] })" class="btn btn-icon btn-ghost" aria-label="Delete role" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="bg-white rounded-2xl border border-gray-100 p-12 text-center">
                            <i class="fas fa-user-tag text-4xl text-gray-200 mb-3"></i>
                            <p class="text-sm text-gray-400">No custom roles yet</p>
                            <p class="text-xs text-gray-300 mt-1">Create roles with specific feature access</p>
                        </div>
                    @endif
                </div>
            @endif
            @if($showMemberForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showMemberForm', false)" x-on:keydown.escape.window="$wire.set('showMemberForm', false)">
                    <div class="modal-box w-full max-w-lg mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingMemberId ? 'Edit' : 'Add' }} Member</h3>
                            <button wire:click="$set('showMemberForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            @if($editingMemberId)
                                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-700"><i class="fas fa-shield-alt mr-1"></i> Password left blank to keep current.</div>
                            @endif
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="form-label">Full Name</label><input type="text" wire:model="formName" class="form-input"><span wire:error="formName" class="text-red-500 text-xs mt-1 block"></span></div>
                                <div><label class="form-label">Email</label><input type="email" wire:model="formEmail" class="form-input"><span wire:error="formEmail" class="text-red-500 text-xs mt-1 block"></span></div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="form-label">Phone</label><input type="text" wire:model="formPhone" class="form-input"></div>
                                <div><label class="form-label">Join Date</label><input type="date" wire:model="formJoinDate" class="form-input"></div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Role</label>
                                    <select wire:model="formRole" class="form-select">
                                        @foreach($this->allRoles as $key => $label)
                                            <option value="{{ $key }}" {{ ($editingMemberId && $key === 'super-admin' && !$isSuperAdminUser) ? 'disabled' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Department</label>
                                    <select wire:model="formDeptId" class="form-select">
                                        <option value="">None</option>
                                        @foreach(DB::table('departments')->orderBy('name')->get() as $dept)
                                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Status</label>
                                    <select wire:model="formStatus" class="form-select">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Password {{ $editingMemberId ? '(optional)' : '' }}</label>
                                    <input type="password" wire:model="formPassword" class="form-input" placeholder="{{ $editingMemberId ? 'Leave blank to keep' : 'Min 8 chars' }}">
                                    <span wire:error="formPassword" class="text-red-500 text-xs mt-1 block"></span>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Base Salary (Monthly)</label>
                                <input type="number" wire:model="formBaseSalary" class="form-input" step="100" min="0" placeholder="e.g. 25000">
                                <p class="text-xs text-gray-400 mt-1">Sets the base salary for the current month. Used in salary calculations.</p>
                                <span wire:error="formBaseSalary" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showMemberForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveMember" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveMember"><span wire:loading.remove wire:target="saveMember">Save</span><span wire:loading wire:target="saveMember" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Department Form Modal --}}
            @if($showDeptForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showDeptForm', false)" x-on:keydown.escape.window="$wire.set('showDeptForm', false)">
                    <div class="modal-box w-full max-w-sm mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingDeptId ? 'Edit' : 'Add' }} Department</h3>
                            <button wire:click="$set('showDeptForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div><label class="form-label">Name</label><input type="text" wire:model="formDeptName" class="form-input"><span wire:error="formDeptName" class="text-red-500 text-xs mt-1 block"></span></div>
                            <div><label class="form-label">Description</label><textarea wire:model="formDeptDesc" class="form-input" rows="2"></textarea></div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showDeptForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveDept" class="btn btn-primary">Save</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Role Form Modal --}}
            @if($showRoleForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showRoleForm', false)" x-on:keydown.escape.window="$wire.set('showRoleForm', false)">
                    <div class="modal-box w-full max-w-lg mx-4 max-h-[85vh] overflow-y-auto">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b z-10">
                            <h3 class="font-bold text-lg">{{ $editingRoleId ? 'Edit' : 'Add' }} Role</h3>
                            <button wire:click="$set('showRoleForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div><label class="form-label">Role Name</label><input type="text" wire:model="formRoleName" class="form-input" placeholder="e.g. Senior Designer"><span wire:error="formRoleName" class="text-red-500 text-xs mt-1 block"></span></div>
                            <div><label class="form-label">Description</label><textarea wire:model="formRoleDesc" class="form-input" rows="2" placeholder="Optional description"></textarea></div>

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
                                        <label class="flex items-center gap-2 text-xs p-1.5 rounded-lg hover:bg-gray-50 cursor-pointer {{ ($formRoleFeatures[$feat] ?? false) ? 'bg-blue-50' : '' }}">
                                            <input type="checkbox" wire:model.live="formRoleFeatures.{{ $feat }}" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

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
                                        <label class="flex items-center gap-2 text-xs p-1.5 rounded-lg hover:bg-gray-50 cursor-pointer {{ ($formRolePerms[$perm] ?? false) ? 'bg-purple-50' : '' }}">
                                            <input type="checkbox" wire:model.live="formRolePerms.{{ $perm }}" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-3.5 h-3.5">
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
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
