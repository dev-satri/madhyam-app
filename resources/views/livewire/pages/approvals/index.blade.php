<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Services\ActivityLogger;
use App\Services\NotificationService;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $clientFilter = '';
    public bool $showForm = false;
    public int $editingId = 0;
    public string $formTitle = '';
    public string $formNotes = '';
    public int $formClientId = 0;
    public string $formStatus = 'pending';
    public string $commentText = '';
    public int $detailId = 0;
    public bool $showDetail = false;
    public array $selectedItems = [];

    public function mount(): void {}

    #[Computed]
    public function approvals()
    {
        return $this->getFilteredApprovals();
    }

    #[Computed]
    public function stats(): array
    {
        return $this->getStats();
    }

    #[Computed]
    public function clients()
    {
        return DB::table('clients')->where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function isManager(): bool
    {
        $user = Auth::user();
        return $user && in_array($user->role ?? '', ['super-admin', 'admin', 'manager']);
    }

    public function getFilteredApprovals()
    {
        // Approvals table (per plan §26 spec) has no content_id — title lives on the row.
        $q = DB::table('approvals')
            ->leftJoin('clients', 'approvals.client_id', '=', 'clients.id')
            ->leftJoin('users', 'approvals.submitted_by', '=', 'users.id');

        if ($this->statusFilter) $q->where('approvals.status', $this->statusFilter);
        if ($this->clientFilter) $q->where('approvals.client_id', $this->clientFilter);

        $user = Auth::user() ?? Auth::guard('client')->user();
        if ($user && !in_array($user->role ?? 'client', ['super-admin', 'admin', 'manager'])) {
            $q->where(function ($q) use ($user) {
                $q->where('approvals.submitted_by', $user->id)
                  ->orWhere('approvals.client_id', $user->client_id ?? 0);
            });
        }

        return $q->select('approvals.*', 'clients.name as client_name', 'users.name as submitter_name')
            ->orderBy('approvals.created_at', 'desc')
            ->paginate(15);
    }

    public function getStats(): array
    {
        $q = DB::table('approvals');
        return [
            'pending' => (clone $q)->where('status', 'pending')->count(),
            'approved' => (clone $q)->where('status', 'approved')->count(),
            'revision' => (clone $q)->where('status', 'revision')->count(),
            'rejected' => (clone $q)->where('status', 'rejected')->count(),
        ];
    }

    public function updateStatus(int $id, string $status): void
    {
        $actor = Auth::user() ?? Auth::guard('client')->user();

        // Client portal cannot reject — only Approve or Revision (spec §22.3).
        if ($status === 'rejected' && $actor && ($actor->role ?? 'client') === 'client') {
            $this->dispatch('toast', message: 'Clients cannot reject approvals', type: 'error');
            return;
        }

        // Revision is a terminal decision — don't overwrite once set (spec §26).
        $current = DB::table('approvals')->where('id', $id)->value('status');
        if ($current === 'revision' && $status !== 'revision') {
            $this->dispatch('toast', message: 'Approval already marked for revision', type: 'warning');
            return;
        }

        DB::table('approvals')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        DB::table('approval_comments')->insert([
            'approval_id' => $id,
            'user_id'     => $actor?->id,
            'user_name'   => $actor?->name ?? 'System',
            'text'        => "Status changed to {$status}",
            'is_system'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        app(ActivityLogger::class)->record($actor, "Approval #{$id} → {$status}");

        // Notify the opposite party: staff decisions notify client, client decisions notify manager.
        $isStaff = $actor && ($actor->role ?? 'client') !== 'client';
        app(NotificationService::class)->sendNotification(
            text: "Approval #{$id} {$status}",
            type: $status === 'approved' ? 'success' : ($status === 'rejected' ? 'error' : 'info'),
            link: route('approvals', absolute: false),
            forRole: $isStaff ? 'client' : 'manager',
        );

        $this->dispatch('toast', message: "Approval {$status}", type: $status === 'approved' ? 'success' : 'info');
    }

    public function bulkApprove(): void
    {
        if (empty($this->selectedItems)) return;
        foreach ($this->selectedItems as $id) {
            $this->updateStatus($id, 'approved');
        }
        $this->selectedItems = [];
        $this->dispatch('toast', message: 'Selected items approved', type: 'success');
    }

    public function toggleSelect(int $id): void
    {
        if (in_array($id, $this->selectedItems)) {
            $this->selectedItems = array_values(array_diff($this->selectedItems, [$id]));
        } else {
            $this->selectedItems[] = $id;
        }
    }

    public function selectAll(): void
    {
        $pending = DB::table('approvals')->where('status', 'pending')->pluck('id')->toArray();
        $this->selectedItems = $pending;
    }

    public function deselectAll(): void
    {
        $this->selectedItems = [];
    }

    public function deleteApproval(int $id): void
    {
        DB::table('approvals')->where('id', $id)->delete();
        app(ActivityLogger::class)->record(Auth::user() ?? Auth::guard('client')->user(), "Deleted approval #{$id}");
        $this->dispatch('toast', message: 'Approval deleted', type: 'success');
    }

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $this->showDetail = true;
        $this->commentText = '';
    }

    public function getDetailApproval()
    {
        return DB::table('approvals')
            ->leftJoin('clients', 'approvals.client_id', '=', 'clients.id')
            ->leftJoin('users', 'approvals.submitted_by', '=', 'users.id')
            ->select('approvals.*', 'clients.name as client_name', 'users.name as submitter_name')
            ->where('approvals.id', $this->detailId)->first();
    }

    public function getDetailComments()
    {
        return DB::table('approval_comments')
            ->join('users', 'approval_comments.user_id', '=', 'users.id')
            ->where('approval_comments.approval_id', $this->detailId)
            ->select('approval_comments.*', 'users.name as user_name')
            ->orderBy('approval_comments.created_at', 'asc')->get();
    }

    public function addComment(): void
    {
        if (!$this->commentText || !$this->detailId) return;
        $actor = Auth::user() ?? Auth::guard('client')->user();
        DB::table('approval_comments')->insert([
            'approval_id' => $this->detailId,
            'user_id'     => $actor?->id,
            'user_name'   => $actor?->name ?? 'Unknown',
            'text'        => $this->commentText,
            'is_system'   => false,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        app(ActivityLogger::class)->record($actor, "Commented on approval #{$this->detailId}");
        $this->commentText = '';
        $this->dispatch('toast', message: 'Comment added', type: 'success');
    }

    public function render(): mixed    {
        return <<<'blade'
        <div class="space-y-6">
            {{-- Banner --}}
            <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                <i class="fas fa-info-circle mr-1"></i>
                {{ $this->isManager ? 'Review and approve content submissions from your team and clients.' : 'Submit and track your content approvals here.' }}
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div><h1 class="text-2xl font-extrabold text-gray-900">Approvals</h1><p class="text-sm text-gray-500">Review and manage content approvals</p></div>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach(['pending'=>'Pending','approved'=>'Approved','revision'=>'Revision','rejected'=>'Rejected'] as $k=>$label)
                <div class="stat-card text-center py-3">
                    <p class="stat-value text-xl font-extrabold {{ $k==='pending'?'text-amber-600':($k==='approved'?'text-green-600':($k==='rejected'?'text-red-600':'text-orange-600')) }}">{{ $this->stats[$k] }}</p>
                    <p class="stat-label text-[11px]">{{ $label }}</p>
                </div>
                @endforeach
            </div>

            {{-- Filters --}}
            <div class="flex flex-wrap gap-3 items-end">
                <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select w-auto"><option value="">All Status</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="revision">Revision</option><option value="rejected">Rejected</option></select></div>
                <div><label class="form-label">Client</label><select wire:model.live="clientFilter" class="form-select w-auto"><option value="">All Clients</option>@foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                @if($this->isManager && count($selectedItems) > 0)
                <div class="flex gap-2"><button wire:click="bulkApprove" class="btn btn-success btn-sm"><i class="fas fa-check-double text-xs"></i> Approve Selected ({{ count($selectedItems) }})</button>
                <button wire:click="deselectAll" class="btn btn-secondary btn-sm">Deselect All</button></div>
                @elseif($this->isManager)
                <div><button wire:click="selectAll" class="btn btn-secondary btn-sm">Select All Pending</button></div>
                @endif
            </div>

            {{-- Approval Cards --}}
            <div class="space-y-3">
                @forelse($this->approvals as $a)
                <div class="bg-white rounded-2xl border border-gray-100 p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-start gap-4">
                        @if($this->isManager && $a->status === 'pending')
                        <input type="checkbox" wire:change="toggleSelect({{ $a->id }})" {{ in_array($a->id, $selectedItems) ? 'checked' : '' }} class="mt-1 h-4 w-4 rounded border-gray-300 text-[var(--brand)]">
                        @endif
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)] shrink-0">
                            <i class="fas fa-check-double text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="text-sm font-bold text-gray-900">{{ $a->title ?? 'Untitled' }}</h4>
                                <span class="badge badge-{{ $a->status }}">{{ ucfirst($a->status) }}</span>
                                @if($a->type)<span class="badge">{{ ucfirst($a->type) }}</span>@endif
                            </div>
                            <p class="text-xs text-gray-500">Client: {{ $a->client_name ?? '—' }} · Submitted by: {{ $a->submitter_name ?? '—' }} · {{ $a->created_at ? \Carbon\Carbon::parse($a->created_at)->diffForHumans() : '' }}</p>
                            @if($a->notes)<p class="text-sm text-gray-600 mt-1">{{ $a->notes }}</p>@endif

                            <div class="flex items-center gap-2 mt-3">
                                @if($a->status === 'pending')
                                <button wire:click="updateStatus({{ $a->id }}, 'approved')" class="btn btn-success btn-sm"><i class="fas fa-check text-xs"></i> Approve</button>
                                <button wire:click="updateStatus({{ $a->id }}, 'revision')" class="btn btn-secondary btn-sm"><i class="fas fa-pen text-xs"></i> Request Revision</button>
                                {{-- Reject is hidden on the client portal (spec §22.3) --}}
                                @if($this->isManager)
                                <button wire:click="updateStatus({{ $a->id }}, 'rejected')" class="btn btn-danger btn-sm"><i class="fas fa-times text-xs"></i> Reject</button>
                                @endif
                                @endif
                                <button wire:click="openDetail({{ $a->id }})" class="btn btn-ghost btn-sm"><i class="fas fa-comments text-xs"></i> Comments</button>
                                @if($this->isManager)<button wire:click="deleteApproval({{ $a->id }})" class="btn btn-ghost btn-sm text-red-500" onclick="return confirm('Delete?')"><i class="fas fa-trash text-xs"></i></button>@endif
                            </div>
                        </div>
                    </div>
                </div>
                @empty
                <div class="bg-white rounded-2xl border border-gray-100 p-12 text-center">
                    <i class="fas fa-check-double text-4xl text-gray-200 mb-3"></i>
                    <p class="text-sm text-gray-400">No approvals found</p>
                </div>
                @endforelse
            </div>

            <div>{{ $this->approvals->links() }}</div>

            {{-- Detail Modal --}}
            @if($showDetail)
            @php $appr = $this->getDetailApproval(); $comments = $this->getDetailComments(); @endphp
            <div class="modal-overlay" wire:click.self="$set('showDetail',false)" x-on:keydown.escape.window="$wire.set('showDetail',false)">
                <div class="modal-box max-w-lg">
                    <div class="modal-header"><h3 class="text-base font-bold text-gray-900">{{ $appr->title ?? 'Approval Details' }}</h3><button wire:click="$set('showDetail',false)" class="btn btn-ghost btn-icon btn-sm"><i class="fas fa-times"></i></button></div>
                    <div class="modal-body space-y-4">
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div><span class="text-gray-500">Status:</span> <span class="badge badge-{{ $appr->status ?? '' }}">{{ ucfirst($appr->status ?? '') }}</span></div>
                            <div><span class="text-gray-500">Client:</span> {{ $appr->client_name ?? '—' }}</div>
                            <div><span class="text-gray-500">Submitted by:</span> {{ $appr->submitter_name ?? '—' }}</div>
                            <div><span class="text-gray-500">Date:</span> {{ $appr->created_at ? \Carbon\Carbon::parse($appr->created_at)->format('M d, Y') : '—' }}</div>
                        </div>
                        @if($appr->notes)<div><h4 class="text-sm font-semibold text-gray-900 mb-1">Notes</h4><p class="text-sm text-gray-600">{{ $appr->notes }}</p></div>@endif

                        <div><h4 class="text-sm font-semibold text-gray-900 mb-2">Comments</h4>
                            <div class="space-y-2 max-h-40 overflow-y-auto">
                                @forelse($comments as $c)
                                <div class="{{ $c->is_system ? 'bg-blue-50' : 'bg-gray-50' }} rounded-lg px-3 py-2">
                                    <p class="text-sm font-medium {{ $c->is_system ? 'text-blue-700' : 'text-gray-900' }}">{{ $c->user_name }} <span class="text-[10px] text-gray-400 font-normal">{{ \Carbon\Carbon::parse($c->created_at)->diffForHumans() }}</span></p>
                                    <p class="text-sm {{ $c->is_system ? 'text-blue-600 italic' : 'text-gray-600' }}">{{ $c->text }}</p>
                                </div>
                                @empty <p class="text-xs text-gray-400">No comments yet</p> @endforelse
                            </div>
                            <div class="flex gap-2 mt-3"><input type="text" wire:model="commentText" class="form-input flex-1" placeholder="Add a comment..."><button wire:click="addComment" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane text-xs"></i></button></div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
        blade;
    }
};
