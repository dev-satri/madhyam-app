<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use App\Models\Leave;
use App\Models\Setting;

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

    public bool $showRejectModal = false;
    public int $rejectTargetId = 0;
    public string $rejectReason = '';

    public bool $showDetail = false;
    public int $detailId = 0;

    public function mount(): void
    {
        $this->formStartDate = now()->format('Y-m-d');
        $this->formEndDate = now()->format('Y-m-d');
    }

    #[On('open-leave-detail')]
    public function openDetail($id): void
    {
        $this->detailId = $id;
        $this->showDetail = true;
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

    public function getLeaves()
    {
        $q = DB::table('leaves')
            ->join('users', 'leaves.member_id', '=', 'users.id')
            ->leftJoin('users as approver', 'leaves.approved_by', '=', 'approver.id');

        if (!$this->isManager()) $q->where('leaves.member_id', Auth::id());
        if ($this->statusFilter) $q->where('leaves.status', $this->statusFilter);

        return $q->select('leaves.*', 'users.name as member_name', 'approver.name as approver_name')
            ->orderByRaw("FIELD(leaves.status, 'pending', 'rejected', 'approved')")
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
            'updated_at' => now(),
        ]);
        $this->dispatch('toast', message: 'Leave approved', type: 'success');
    }

    public function openRejectModal(int $id): void
    {
        $this->rejectTargetId = $id;
        $this->rejectReason = '';
        $this->showRejectModal = true;
    }

    public function submitRejection(): void
    {
        if (!trim($this->rejectReason)) {
            $this->dispatch('toast', message: 'Please provide a reason for rejection', type: 'error');
            return;
        }
        DB::table('leaves')->where('id', $this->rejectTargetId)->update([
            'status' => 'rejected',
            'approved_by' => Auth::id(),
            'rejection_reason' => trim($this->rejectReason),
            'updated_at' => now(),
        ]);
        $this->showRejectModal = false;
        $this->dispatch('toast', message: 'Leave rejected', type: 'success');
    }

    public function deleteLeave(int $id): void
    {
        Leave::findOrFail($id)->delete();
        $this->dispatch('toast', message: 'Leave deleted', type: 'success');
    }

    public function exportCsv()
    {
        $leaves = $this->getLeaves();
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="leaves_export.csv"',
        ];
        $callback = function () use ($leaves) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Member', 'Type', 'Start Date', 'End Date', 'Reason', 'Status', 'Rejection Reason', 'Requested Date']);
            foreach ($leaves as $l) {
                fputcsv($file, [
                    $l->member_name,
                    ucfirst($l->type),
                    $l->start_date,
                    $l->end_date,
                    $l->reason ?? '',
                    ucfirst($l->status),
                    $l->rejection_reason ?? '',
                    $l->created_at,
                ]);
            }
            fclose($file);
        };
        return Response::stream($callback, 200, $headers);
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
    public function team()
    {
        return DB::table('users')->where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function isMgr(): bool
    {
        return $this->isManager();
    }

    #[Computed]
    public function remainingPaidLeaves(): array
    {
        $userId = Auth::id();
        $settings = Setting::current();
        $maxPaid = (int) $settings->paid_leaves_per_year;
        $year = (int) now()->year;
        $yearStart = \Carbon\Carbon::createFromDate($year, 1, 1);
        $yearEnd = \Carbon\Carbon::createFromDate($year, 12, 31);

        $yearLeaves = DB::table('leaves')
            ->where('member_id', $userId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $yearEnd)
            ->whereDate('end_date', '>=', $yearStart)
            ->get();

        $used = 0;
        foreach ($yearLeaves as $l) {
            $days = (int) \Carbon\Carbon::parse($l->start_date)->diffInDays(\Carbon\Carbon::parse($l->end_date)) + 1;
            if (in_array($l->type, ['annual', 'casual'], true)) {
                $used += $days;
            }
        }

        return [
            'max' => $maxPaid,
            'used' => $used,
            'remaining' => max(0, $maxPaid - $used),
        ];
    }

    #[Computed]
    public function detailLeave()
    {
        return DB::table('leaves')
            ->join('users', 'leaves.member_id', '=', 'users.id')
            ->leftJoin('users as approver', 'leaves.approved_by', '=', 'approver.id')
            ->select('leaves.*', 'users.name as member_name', 'users.email as member_email', 'approver.name as approver_name')
            ->where('leaves.id', $this->detailId)
            ->first();
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Leaves</h1>
                    <p class="text-sm text-gray-500">Manage leave requests and approvals</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('leaves') }}/export" wire:click.prevent="$wire.exportCsv()" class="btn btn-secondary btn-sm"><i class="fas fa-download text-xs"></i> Export CSV</a>
                    <button wire:click="openForm" class="btn btn-primary btn-sm"><i class="fas fa-plus text-xs"></i> Apply Leave</button>
                </div>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <button wire:click="$set('statusFilter','')" class="stat-card text-center py-3 text-left transition-all {{ $statusFilter === '' ? 'ring-2 ring-[var(--brand)]' : '' }}">
                    <p class="stat-value text-xl font-extrabold text-blue-600">{{ $this->stats['my_leaves'] }}</p>
                    <p class="stat-label text-[11px]">My Leaves</p>
                </button>
                <button wire:click="$set('statusFilter','pending')" class="stat-card text-center py-3 text-left transition-all {{ $statusFilter === 'pending' ? 'ring-2 ring-[var(--brand)]' : '' }}">
                    <p class="stat-value text-xl font-extrabold text-amber-600">{{ $this->stats['pending'] }}</p>
                    <p class="stat-label text-[11px]">Pending</p>
                </button>
                <button wire:click="$set('statusFilter','approved')" class="stat-card text-center py-3 text-left transition-all {{ $statusFilter === 'approved' ? 'ring-2 ring-[var(--brand)]' : '' }}">
                    <p class="stat-value text-xl font-extrabold text-green-600">{{ $this->stats['approved'] }}</p>
                    <p class="stat-label text-[11px]">Approved</p>
                </button>
                <button wire:click="$set('statusFilter','rejected')" class="stat-card text-center py-3 text-left transition-all {{ $statusFilter === 'rejected' ? 'ring-2 ring-[var(--brand)]' : '' }}">
                    <p class="stat-value text-xl font-extrabold text-red-600">{{ $this->stats['rejected'] }}</p>
                    <p class="stat-label text-[11px]">Rejected</p>
                </button>
                <div class="stat-card text-center py-3 text-left bg-purple-50 border-purple-200">
                    <p class="stat-value text-xl font-extrabold text-purple-600">{{ $this->remainingPaidLeaves['remaining'] }}<span class="text-xs text-purple-400 font-normal">/{{ $this->remainingPaidLeaves['max'] }}</span></p>
                    <p class="stat-label text-[11px]">Paid Leaves Left</p>
                </div>
            </div>

            {{-- Filters --}}
            <div class="flex gap-3 items-end">
                <div class="w-48">
                    <label class="form-label">Status</label>
                    <select wire:model.live="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </div>

            {{-- Leaves Cards --}}
            <div class="space-y-3">
                @forelse($this->leaves as $l)
                <div class="bg-white rounded-2xl border border-gray-100 p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)] shrink-0">
                            <i class="fas fa-calendar-day text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="text-sm font-bold text-gray-900">{{ $l->member_name }}</h4>
                                <span class="badge {{ $l->type_class }}">{{ ucfirst($l->type) }}</span>
                                <span class="badge {{ $l->status_class }}">{{ ucfirst($l->status) }}</span>
                            </div>
                            <p class="text-xs text-gray-500">
                                {{ \Carbon\Carbon::parse($l->start_date)->format('M d, Y') }} — {{ \Carbon\Carbon::parse($l->end_date)->format('M d, Y') }}
                                · {{ \Carbon\Carbon::parse($l->start_date)->diffInDays(\Carbon\Carbon::parse($l->end_date)) + 1 }} day(s)
                            </p>
                            @if($l->reason)
                            <p class="text-sm text-gray-600 mt-1">{{ $l->reason }}</p>
                            @endif

                            <div class="flex items-center justify-between mt-3">
                                <div class="flex items-center gap-2 flex-wrap">
                                    @if($l->status === 'pending' && $this->isMgr)
                                    <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve Leave?', message: 'This will approve the leave request for ' + '{{ addslashes($l->member_name) }}' + '.', type: 'info', action: 'approveLeave', params: [{{ $l->id }}] })" class="btn btn-success btn-sm"><i class="fas fa-check text-xs"></i> Approve</button>
                                    <button type="button" wire:click="openRejectModal({{ $l->id }})" class="btn btn-danger btn-sm"><i class="fas fa-times text-xs"></i> Reject</button>
                                    @endif
                                    @if($l->status === 'rejected' && $l->rejection_reason)
                                    <span class="text-xs text-red-600"><i class="fas fa-times-circle mr-1"></i>{{ $l->rejection_reason }} @if($l->approver_name)<span class="text-red-400">— {{ $l->approver_name }}</span>@endif</span>
                                    @endif
                                    @if($l->status === 'approved' && $l->approver_name)
                                    <span class="text-xs text-green-600"><i class="fas fa-check-circle mr-1"></i>Approved by {{ $l->approver_name }}</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button" wire:click="$dispatch('open-leave-detail', {{ $l->id }})" class="btn btn-ghost btn-sm" aria-label="View leave"><i class="fas fa-eye text-xs"></i></button>
                                    @if($this->isMgr)
                                    <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Leave?', message: 'This leave request will be permanently removed.', type: 'danger', action: 'deleteLeave', params: [{{ $l->id }}] })" class="btn btn-ghost btn-sm text-red-500" aria-label="Delete leave"><i class="fas fa-trash text-xs"></i></button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @empty
                <div class="bg-white rounded-2xl border border-gray-100 p-12 text-center">
                    <i class="fas fa-calendar-minus text-4xl text-gray-200 mb-3"></i>
                    <p class="text-sm text-gray-400">No leaves found</p>
                </div>
                @endforelse
            </div>

            {{-- Leave Form Modal --}}
            @if($showForm)
            <div class="modal-overlay" wire:click.self="$set('showForm', false)" x-on:keydown.escape.window="$wire.set('showForm', false)">
                <div class="modal-box max-w-md">
                    <div class="modal-header">
                        <h3 class="text-base font-bold text-gray-900">{{ $editingId ? 'Edit' : 'Apply for' }} Leave</h3>
                        <button wire:click="$set('showForm', false)" class="btn btn-ghost btn-icon btn-sm" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="modal-body space-y-4">
                        <div>
                            <label class="form-label">Leave Type</label>
                            <select wire:model="formType" class="form-select">
                                <option value="casual">Casual</option><option value="sick">Sick</option>
                                <option value="annual">Annual</option><option value="personal">Personal</option>
                            </select>
                        </div>
                        @if($this->isMgr)
                        <div>
                            <label class="form-label">Team Member</label>
                            <select wire:model="formMemberId" class="form-select"><option value="">Self</option>
                                @foreach($this->team as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </select>
                        </div>
                        @endif
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="form-label">Start Date</label><input type="date" wire:model="formStartDate" class="form-input"></div>
                            <div><label class="form-label">End Date</label><input type="date" wire:model="formEndDate" class="form-input"></div>
                        </div>
                        <div><label class="form-label">Reason</label><textarea wire:model="formReason" class="form-textarea" rows="2" placeholder="Optional reason"></textarea></div>
                    </div>
                    <div class="flex justify-end gap-2 p-4 border-t border-gray-100">
                        <button wire:click="$set('showForm', false)" class="btn btn-secondary btn-sm">Cancel</button>
                        <button wire:click="save" class="btn btn-primary btn-sm" wire:loading.attr="disabled"><i class="fas fa-save text-xs"></i> Submit</button>
                    </div>
                </div>
            </div>
            @endif

            {{-- Reject Reason Modal --}}
            @if($showRejectModal)
            <div class="modal-overlay" wire:click.self="$set('showRejectModal',false)" x-on:keydown.escape.window="$wire.set('showRejectModal',false)">
                <div class="modal-box max-w-md">
                    <div class="modal-header">
                        <h3 class="text-base font-bold text-gray-900">Reject Leave Request</h3>
                        <button wire:click="$set('showRejectModal',false)" class="btn btn-ghost btn-icon btn-sm" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="modal-body space-y-4">
                        <p class="text-sm text-gray-500">Please provide a reason for rejection. This will be visible to the employee.</p>
                        <div>
                            <label class="form-label">Reason for Rejection</label>
                            <textarea wire:model="rejectReason" class="form-textarea" rows="4" placeholder="e.g. Does not meet team schedule requirements..."></textarea>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button wire:click="$set('showRejectModal',false)" class="btn btn-secondary btn-sm">Cancel</button>
                            <button wire:click="submitRejection" class="btn btn-danger btn-sm" wire:loading.attr="disabled"><i class="fas fa-times text-xs"></i> Reject</button>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Leave Detail Modal --}}
            @if($showDetail)
            @php $dl = $this->detailLeave; @endphp
            <div class="modal-overlay" wire:click.self="$set('showDetail',false)" x-on:keydown.escape.window="$wire.set('showDetail',false)">
                <div class="modal-box max-w-md">
                    <div class="modal-header">
                        <h3 class="text-base font-bold text-gray-900">Leave Request</h3>
                        <button wire:click="$set('showDetail',false)" class="btn btn-ghost btn-icon btn-sm" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="modal-body space-y-4">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center font-bold text-sm shrink-0">{{ strtoupper(substr($dl->member_name ?? '', 0, 2)) }}</div>
                            <div>
                                <p class="font-bold text-gray-900">{{ $dl->member_name ?? '' }}</p>
                                <p class="text-xs text-gray-500">{{ $dl->member_email ?? '' }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div><span class="text-gray-500">Type:</span> <span class="badge badge-{{ $dl->type === 'sick' ? 'danger' : ($dl->type === 'casual' ? 'info' : ($dl->type === 'annual' ? 'success' : 'warning')) }}">{{ ucfirst($dl->type ?? '') }}</span></div>
                            <div><span class="text-gray-500">Status:</span> <span class="badge badge-{{ $dl->status === 'approved' ? 'success' : ($dl->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($dl->status ?? '') }}</span></div>
                            <div><span class="text-gray-500">Start:</span> {{ $dl->start_date ? \Carbon\Carbon::parse($dl->start_date)->format('M d, Y') : '—' }}</div>
                            <div><span class="text-gray-500">End:</span> {{ $dl->end_date ? \Carbon\Carbon::parse($dl->end_date)->format('M d, Y') : '—' }}</div>
                            <div class="col-span-2"><span class="text-gray-500">Duration:</span> {{ $dl->start_date && $dl->end_date ? \Carbon\Carbon::parse($dl->start_date)->diffInDays(\Carbon\Carbon::parse($dl->end_date)) + 1 : 0 }} day(s)</div>
                        </div>

                        @if($dl->reason)
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-1">Reason</h4>
                            <p class="text-sm text-gray-600">{{ $dl->reason }}</p>
                        </div>
                        @endif

                        @if($dl->status === 'rejected' && $dl->rejection_reason)
                        <div class="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-sm text-red-700">
                            <i class="fas fa-times-circle mr-1"></i> <span class="font-semibold">Rejection reason:</span> {{ $dl->rejection_reason }}
                            @if($dl->approver_name) <span class="text-xs text-red-500 ml-1">— by {{ $dl->approver_name }}</span> @endif
                        </div>
                        @endif

                        @if($dl->status === 'approved' && $dl->approver_name)
                        <div class="bg-green-50 border border-green-200 rounded-lg px-3 py-2 text-sm text-green-700">
                            <i class="fas fa-check-circle mr-1"></i> Approved by {{ $dl->approver_name }}
                        </div>
                        @endif

                        <div class="text-xs text-gray-400">Requested {{ $dl->created_at ? \Carbon\Carbon::parse($dl->created_at)->diffForHumans() : '' }}</div>

                        <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                            @if($dl->status === 'pending' && $this->isMgr)
                            <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve Leave?', message: 'This will approve the leave request.', type: 'info', action: 'approveLeave', params: [{{ $dl->id }}] })" class="btn btn-success btn-sm"><i class="fas fa-check text-xs"></i> Approve</button>
                            <button type="button" wire:click="$set('showDetail',false); openRejectModal({{ $dl->id }})" class="btn btn-danger btn-sm"><i class="fas fa-times text-xs"></i> Reject</button>
                            @endif
                            <button wire:click="$set('showDetail',false)" class="btn btn-secondary btn-sm">Close</button>
                        </div>
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
