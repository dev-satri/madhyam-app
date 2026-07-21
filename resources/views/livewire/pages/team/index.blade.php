<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\RbacService;

new #[Layout('components.layouts.app')] class extends Component
{
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

    // Department form
    public string $formDeptName = '';
    public string $formDeptDesc = '';

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
            ->get()
            ->map(function ($m) {
                $m->role_class = 'role-' . $m->role;
                return $m;
            });
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
        } else {
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('users')->insert($data);
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

            {{-- Role Count Cards --}}
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
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
            </div>

            {{-- Members Tab --}}
            @if($activeTab === 'members')
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                    <div><label class="form-label">Search</label><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="search" wire:model.live.debounce.250ms="search" placeholder="Search name or email..." class="form-input pl-10"></div></div>
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
                                        <span class="badge {{ $m->role_class }}">
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
                                            <div class="flex gap-1">
                                                <button wire:click="openMemberForm({{ $m->id }})" class="text-gray-400 hover:text-blue-500"><i class="fas fa-pen text-xs"></i></button>
                                                @if($m->role !== 'super-admin' && $m->id !== Auth::id())
                                                    <button wire:click="deleteMember({{ $m->id }})" wire:confirm="Are you sure you want to delete this team member?" class="text-gray-400 hover:text-red-500"><i class="fas fa-trash text-xs"></i></button>
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
                                            <div class="flex gap-1">
                                                <button wire:click="openDeptForm({{ $d->id }})" class="text-gray-400 hover:text-blue-500"><i class="fas fa-pen text-xs"></i></button>
                                                <button wire:click="deleteDept({{ $d->id }})" wire:confirm="Are you sure you want to delete this department?" class="text-gray-400 hover:text-red-500"><i class="fas fa-trash text-xs"></i></button>
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

            {{-- Member Form Modal --}}
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
                                <div><label class="form-label">Full Name</label><input type="text" wire:model="formName" class="form-input"></div>
                                <div><label class="form-label">Email</label><input type="email" wire:model="formEmail" class="form-input"></div>
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
                                </div>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showMemberForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveMember" class="btn btn-primary">Save</button>
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
                            <div><label class="form-label">Name</label><input type="text" wire:model="formDeptName" class="form-input"></div>
                            <div><label class="form-label">Description</label><textarea wire:model="formDeptDesc" class="form-input" rows="2"></textarea></div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showDeptForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveDept" class="btn btn-primary">Save</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        blade;
    }
};
