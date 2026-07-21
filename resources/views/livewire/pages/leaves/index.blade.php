<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $statusFilter = '';
    public bool $showForm = false;
    public int $editingId = 0;
    public string $formType = 'casual';
    public int $formMemberId = 0;
    public string $formStartDate = '';
    public string $formEndDate = '';
    public string $formReason = '';

    public function mount(): void
    {
        $this->formStartDate = now()->format('Y-m-d');
        $this->formEndDate = now()->format('Y-m-d');
    }

    public function isManager(): bool
    {
        $user = Auth::user();
        return in_array($user->role, ['super-admin', 'admin', 'manager']);
    }

    public function getStats(): array
    {
        $userId = Auth::id();
        $isMgr = $this->isManager();
        $q = DB::table('leaves');
        if (!$isMgr) $q->where('member_id', $userId);

        return [
            'my_leaves' => (clone $q)->where('member_id', $userId)->count(),
            'pending' => DB::table('leaves')->where('status', 'pending')->count(),
            'approved' => DB::table('leaves')->where('status', 'approved')->count(),
            'rejected' => DB::table('leaves')->where('status', 'rejected')->count(),
        ];
    }

    public function getPendingApprovals()
    {
        if (!$this->isManager()) return collect();
        return DB::table('leaves')
            ->join('users', 'leaves.member_id', '=', 'users.id')
            ->where('leaves.status', 'pending')
            ->select('leaves.*', 'users.name as member_name', 'users.avatar')
            ->orderBy('leaves.created_at', 'desc')
            ->get();
    }

    public function getLeaves()
    {
        $q = DB::table('leaves')
            ->join('users', 'leaves.member_id', '=', 'users.id');

        if (!$this->isManager()) $q->where('leaves.member_id', Auth::id());
        if ($this->statusFilter) $q->where('leaves.status', $this->statusFilter);

        return $q->select('leaves.*', 'users.name as member_name')
            ->orderBy('leaves.created_at', 'desc')
            ->get()
            ->map(function ($l) {
                $l->type_class = match($l->type) {
                    'sick' => 'badge-danger',
                    'casual' => 'badge-info',
                    'annual' => 'badge-success',
                    'personal' => 'badge-warning',
                    default => 'badge-gray',
                };
                $l->status_class = match($l->status) {
                    'approved' => 'badge-success',
                    'rejected' => 'badge-danger',
                    default => 'badge-warning',
                };
                return $l;
            });
    }

    public function openForm(?int $id = null): void
    {
        if ($id) {
            $leave = DB::table('leaves')->where('id', $id)->first();
            if ($leave) {
                $this->editingId = $id;
                $this->formType = $leave->type;
                $this->formMemberId = $leave->member_id;
                $this->formStartDate = $leave->start_date;
                $this->formEndDate = $leave->end_date;
                $this->formReason = $leave->reason ?? '';
            }
        } else {
            $this->editingId = 0;
            $this->formType = 'casual';
            $this->formMemberId = $this->isManager() ? 0 : Auth::id();
            $this->formStartDate = now()->format('Y-m-d');
            $this->formEndDate = now()->format('Y-m-d');
            $this->formReason = '';
        }
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'formType' => 'required|in:sick,casual,annual,personal',
            'formStartDate' => 'required|date',
            'formEndDate' => 'required|date|after_or_equal:formStartDate',
        ]);

        $memberId = $this->isManager() ? ($this->formMemberId ?: Auth::id()) : Auth::id();

        $data = [
            'type' => $this->formType,
            'member_id' => $memberId,
            'start_date' => $this->formStartDate,
            'end_date' => $this->formEndDate,
            'reason' => $this->formReason ?: null,
        ];

        if ($this->editingId) {
            DB::table('leaves')->where('id', $this->editingId)->update($data);
        } else {
            $data['status'] = 'pending';
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('leaves')->insert($data);
        }

        $this->showForm = false;
        $this->dispatch('toast', message: 'Leave saved', type: 'success');
    }

    public function approveLeave(int $id): void
    {
        DB::table('leaves')->where('id', $id)->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
        ]);
        $this->dispatch('toast', message: 'Leave approved', type: 'success');
    }

    public function rejectLeave(int $id): void
    {
        DB::table('leaves')->where('id', $id)->update([
            'status' => 'rejected',
            'approved_by' => Auth::id(),
        ]);
        $this->dispatch('toast', message: 'Leave rejected', type: 'success');
    }

    public function deleteLeave(int $id): void
    {
        DB::table('leaves')->where('id', $id)->delete();
        $this->dispatch('toast', message: 'Leave deleted', type: 'success');
    }

    #[Computed]
    public function stats(): array
    {
        return $this->getStats();
    }

    #[Computed]
    public function leaves()
    {
        return $this->getLeaves();
    }

    #[Computed]
    public function pending()
    {
        return $this->getPendingApprovals();
    }

    #[Computed]
    public function team()
    {
        return DB::table('users')->where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function isMgr(): bool
    {
        return $this->isManager();
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div><h1 class="text-2xl font-extrabold text-gray-900">Leaves</h1><p class="text-sm text-gray-500">Manage leave requests and approvals</p></div>
                <button wire:click="openForm" class="btn btn-primary"><i class="fas fa-plus text-sm"></i> Apply Leave</button>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-calendar"></i></div><div class="stat-value">{{ $this->stats['my_leaves'] }}</div><div class="stat-label">My Leaves</div></div>
                <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-clock"></i></div><div class="stat-value">{{ $this->stats['pending'] }}</div><div class="stat-label">Pending</div></div>
                <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-check"></i></div><div class="stat-value">{{ $this->stats['approved'] }}</div><div class="stat-label">Approved</div></div>
                <div class="stat-card"><div class="stat-icon bg-red-100 text-red-600"><i class="fas fa-times"></i></div><div class="stat-value">{{ $this->stats['rejected'] }}</div><div class="stat-label">Rejected</div></div>
            </div>

            {{-- Pending Approvals (Manager+) --}}
            @if($this->isMgr && $this->pending->count())
                <div>
                    <h2 class="font-bold text-lg mb-3">Pending Approvals</h2>
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach($this->pending as $p)
                            <div class="bg-white border rounded-xl p-4 flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center font-bold text-sm">{{ strtoupper(substr($p->member_name, 0, 2)) }}</div>
                                <div class="flex-1">
                                    <div class="font-semibold">{{ $p->member_name }}</div>
                                    <div class="text-sm text-gray-600">{{ ucfirst($p->type) }} Leave</div>
                                    <div class="text-xs text-gray-500">{{ fmtDate($p->start_date) }} — {{ fmtDate($p->end_date) }}</div>
                                    @if($p->reason)<div class="text-xs text-gray-500 mt-1">{{ $p->reason }}</div>@endif
                                </div>
                                <div class="flex gap-1">
                                    <button wire:click="approveLeave({{ $p->id }})" class="btn btn-success btn-sm"><i class="fas fa-check"></i></button>
                                    <button wire:click="rejectLeave({{ $p->id }})" class="btn btn-danger btn-sm"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Filters --}}
            <div class="flex gap-3 items-end">
                <div class="w-48"><label class="form-label">Status</label><select wire:model="statusFilter" class="form-select">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select></div>
            </div>

            {{-- Leaves Table --}}
            <div class="overflow-x-auto">
                <table class="data-table w-full">
                    <thead><tr><th>Member</th><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse($this->leaves as $l)
                            <tr>
                                <td class="font-medium">{{ $l->member_name }}</td>
                                <td><span class="badge {{ $l->type_class }}">{{ ucfirst($l->type) }}</span></td>
                                <td class="text-sm">{{ fmtDate($l->start_date) }} — {{ fmtDate($l->end_date) }}</td>
                                <td class="text-sm text-gray-600 max-w-[200px] truncate">{{ $l->reason ?? '-' }}</td>
                                <td><span class="badge {{ $l->status_class }}">{{ ucfirst($l->status) }}</span></td>
                                <td>
                                    @if($l->status === 'pending' && $this->isMgr)
                                        <div class="flex items-center gap-1">
                                            <button wire:click="approveLeave({{ $l->id }})" class="btn btn-icon btn-ghost" title="Approve"><i class="fas fa-check text-gray-400 hover:text-green-500 text-xs"></i></button>
                                            <button wire:click="rejectLeave({{ $l->id }})" class="btn btn-icon btn-ghost" title="Reject"><i class="fas fa-times text-gray-400 hover:text-red-500 text-xs"></i></button>
                                        </div>
                                    @else
                                        <button wire:click="deleteLeave({{ $l->id }})" wire:confirm="Are you sure you want to delete this leave request?" class="btn btn-icon btn-ghost" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">
                                <div class="py-16 text-center">
                                    <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"><i class="fas fa-calendar-minus text-2xl text-gray-300"></i></div>
                                    <p class="text-gray-500 font-medium text-sm">No leaves found</p>
                                    <p class="text-gray-400 text-xs mt-1">Submit a leave request to get started</p>
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Leave Form Modal --}}
            @if($showForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showForm', false)" x-on:keydown.escape.window="$wire.set('showForm', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingId ? 'Edit' : 'Apply for' }} Leave</h3>
                            <button wire:click="$set('showForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div><label class="form-label">Leave Type</label>
                                <select wire:model="formType" class="form-select">
                                    <option value="casual">Casual</option><option value="sick">Sick</option>
                                    <option value="annual">Annual</option><option value="personal">Personal</option>
                                </select>
                            </div>
                            @if($this->isMgr)
                                <div><label class="form-label">Team Member</label>
                                    <select wire:model="formMemberId" class="form-select"><option value="">Self</option>
                                        @foreach($this->team as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                                    </select>
                                </div>
                            @endif
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="form-label">Start Date</label><input type="date" wire:model="formStartDate" class="form-input"><span wire:error="formStartDate" class="text-red-500 text-xs mt-1 block"></span></div>
                                <div><label class="form-label">End Date</label><input type="date" wire:model="formEndDate" class="form-input"><span wire:error="formEndDate" class="text-red-500 text-xs mt-1 block"></span></div>
                            </div>
                            <div><label class="form-label">Reason</label><textarea wire:model="formReason" class="form-input" rows="2" placeholder="Optional reason"></textarea></div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">Submit</span><span wire:loading wire:target="save" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Submitting...</span></button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        blade;
    }
};
