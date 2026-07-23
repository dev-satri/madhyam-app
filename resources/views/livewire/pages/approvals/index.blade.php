<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Services\ActivityLogger;
use App\Services\NotificationService;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $clientFilter = '';
    public string $search = '';
    public string $typeFilter = '';
    public bool $showForm = false;
    public int $editingId = 0;
    public string $formTitle = '';
    public string $formNotes = '';
    public int $formClientId = 0;
    public ?int $formContentId = null;
    public string $formStatus = 'pending';
    public string $formType = 'post';
    public string $formReferenceFile = '';
    public string $commentText = '';
    public int $detailId = 0;
    public bool $showDetail = false;
    public array $selectedItems = [];
    public bool $showReasonModal = false;
    public string $reasonAction = '';
    public array $reasonTargets = [];
    public string $reasonText = '';

    public function mount(): void {}

    public function getAvailableContent(): \Illuminate\Support\Collection
    {
        if (!$this->formClientId) return collect();
        return DB::table('contents')
            ->where('client_id', $this->formClientId)
            ->orderBy('date', 'desc')
            ->get();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedClientFilter(): void { $this->resetPage(); }

    public function toggleStatusFilter(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status ? '' : $status;
    }

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

    #[Computed]
    public function commentCounts(): array
    {
        $ids = collect($this->approvals)->pluck('id')->toArray();
        if (empty($ids)) return [];
        return DB::table('approval_comments')
            ->whereIn('approval_id', $ids)
            ->select('approval_id', DB::raw('count(*) as cnt'))
            ->groupBy('approval_id')
            ->pluck('cnt', 'approval_id')
            ->toArray();
    }

    public function isImage(?string $path): bool
    {
        if (!$path) return false;
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|svg|bmp|ico)$/i', $path);
    }

    public function fileUrl(?string $path): string
    {
        if (!$path) return '';
        if (str_starts_with($path, 'http')) return $path;
        return Storage::url($path);
    }

    public function getFilteredApprovals()
    {
        // Approvals table (per plan §26 spec) has no content_id — title lives on the row.
        $q = DB::table('approvals')
            ->leftJoin('clients', 'approvals.client_id', '=', 'clients.id')
            ->leftJoin('users', 'approvals.submitted_by', '=', 'users.id');

        if ($this->statusFilter) $q->where('approvals.status', $this->statusFilter);
        if ($this->clientFilter) $q->where('approvals.client_id', $this->clientFilter);
        if ($this->typeFilter) $q->where('approvals.type', $this->typeFilter);
        if ($this->search) {
            $q->where(function ($q) {
                $q->where('approvals.title', 'like', "%{$this->search}%")
                  ->orWhere('users.name', 'like', "%{$this->search}%");
            });
        }

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
        if ($status === 'rejected' && $actor && ($actor->role ?? 'client') === 'client') {
            $this->dispatch('toast', message: 'Clients cannot reject approvals', type: 'error');
            return;
        }
        $current = DB::table('approvals')->where('id', $id)->value('status');
        // Allow: pending → any, revision → approved/rejected
        $allowed = ($current === 'pending') || ($current === 'revision' && in_array($status, ['approved', 'rejected']));
        if (!$allowed) {
            $this->dispatch('toast', message: 'Cannot change this approval status', type: 'warning');
            return;
        }
        $this->applyStatus($id, $status);
        $this->dispatch('toast', message: "Approval {$status}", type: $status === 'approved' ? 'success' : 'info');
        $this->resetPage();
    }

    private function applyStatus(int $id, string $status, ?string $reason = null, bool $suppressSideEffects = false): bool
    {
        $actor = Auth::user() ?? Auth::guard('client')->user();
        $current = DB::table('approvals')->where('id', $id)->value('status');
        $allowed = ($current === 'pending') || ($current === 'revision' && in_array($status, ['approved', 'rejected']));
        if (!$allowed) return false;

        DB::table('approvals')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);

        if ($reason) {
            DB::table('approval_comments')->insert([
                'approval_id' => $id, 'user_id' => $actor?->id, 'user_name' => $actor?->name ?? 'Unknown',
                'text' => $reason, 'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('approval_comments')->insert([
            'approval_id' => $id, 'user_id' => $actor?->id, 'user_name' => $actor?->name ?? 'System',
            'text' => "Status changed to {$status}", 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ActivityLogger::class)->record($actor, "Approval #{$id} → {$status}");

        if (!$suppressSideEffects) {
            $isStaff = $actor && ($actor->role ?? 'client') !== 'client';
            app(NotificationService::class)->sendNotification(
                text: "Approval #{$id} {$status}",
                type: $status === 'approved' ? 'success' : ($status === 'rejected' ? 'error' : 'info'),
                link: route('approvals', absolute: false),
                forRole: $isStaff ? 'client' : 'manager',
            );
        }
        return true;
    }

    public function bulkApprove(): void
    {
        $this->bulkAct('approved');
    }

    public function bulkAct(string $action, ?string $reason = null): void
    {
        if (empty($this->selectedItems)) return;
        $actor = Auth::user() ?? Auth::guard('client')->user();
        if ($action === 'rejected' && $actor && ($actor->role ?? 'client') === 'client') {
            $this->dispatch('toast', message: 'Clients cannot reject approvals', type: 'error');
            return;
        }
        $pending = DB::table('approvals')->where('status', 'pending')->pluck('id')->toArray();
        $targets = array_values(array_intersect($this->selectedItems, $pending));
        $count = 0;
        foreach ($targets as $id) {
            if ($this->applyStatus($id, $action, $reason, suppressSideEffects: true)) $count++;
        }
        if ($count > 0) {
            $isStaff = $actor && ($actor->role ?? 'client') !== 'client';
            app(NotificationService::class)->sendNotification(
                text: "{$count} approval(s) {$action} by {$actor?->name}",
                type: $action === 'approved' ? 'success' : ($action === 'rejected' ? 'error' : 'info'),
                link: route('approvals', absolute: false),
                forRole: $isStaff ? 'client' : 'manager',
            );
            $this->dispatch('toast', message: "{$count} approval(s) {$action}", type: 'success');
        }
        $this->selectedItems = [];
        $this->resetPage();
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
        if (!$this->isManager) {
            $this->dispatch('toast', message: 'Unauthorized action', type: 'error');
            return;
        }
        DB::table('approvals')->where('id', $id)->delete();
        app(ActivityLogger::class)->record(Auth::user() ?? Auth::guard('client')->user(), "Deleted approval #{$id}");
        $this->resetPage();
        $this->dispatch('toast', message: 'Approval deleted', type: 'success');
    }

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $this->showDetail = true;
        $this->commentText = '';
    }

    public function canEdit(object $a): bool
    {
        return $this->isManager;
    }

    public function edit(int $id): void
    {
        if (!$this->isManager) return;
        $row = DB::table('approvals')->where('id', $id)->first();
        if (!$row) return;
        $this->editingId = (int) $row->id;
        $this->formTitle = $row->title ?? '';
        $this->formNotes = $row->notes ?? '';
        $this->formClientId = (int) ($row->client_id ?? 0);
        $this->formContentId = $row->content_id ?? null;
        $this->formType = $row->type ?? 'post';
        $this->formReferenceFile = $row->reference_file ?? '';
        $this->showDetail = false;
        $this->showForm = true;
    }

    public function create(): void
    {
        if (!$this->isManager) return;
        $this->editingId = 0;
        $this->formTitle = '';
        $this->formNotes = '';
        $this->formClientId = 0;
        $this->formContentId = null;
        $this->formType = 'post';
        $this->formReferenceFile = '';
        $this->showForm = true;
    }

    public function createOrUpdate(): void
    {
        if (!$this->isManager) return;
        $actor = Auth::user();
        $data = [
            'title' => $this->formTitle,
            'type' => $this->formType,
            'client_id' => $this->formClientId ?: null,
            'content_id' => $this->formContentId ?: null,
            'notes' => $this->formNotes,
            'reference_file' => $this->formReferenceFile ?: null,
        ];
        if ($this->editingId > 0) {
            DB::table('approvals')->where('id', $this->editingId)->update($data + ['updated_at' => now()]);
            $this->dispatch('toast', message: 'Approval updated', type: 'success');
        } else {
            $data['status'] = 'pending';
            $data['submitted_by'] = $actor?->id;
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('approvals')->insert($data);
            $this->dispatch('toast', message: 'Approval created', type: 'success');
        }
        $this->showForm = false;
        $this->resetPage();
    }

    public function openReasonModal(int $id, string $action): void
    {
        $actor = Auth::user() ?? Auth::guard('client')->user();
        if ($action === 'rejected' && $actor && ($actor->role ?? 'client') === 'client') {
            $this->dispatch('toast', message: 'Clients cannot reject approvals', type: 'error');
            return;
        }
        $this->reasonAction = $action;
        $this->reasonTargets = [$id];
        $this->reasonText = '';
        $this->showReasonModal = true;
    }

    public function openBulkReasonModal(string $action): void
    {
        $actor = Auth::user() ?? Auth::guard('client')->user();
        if ($action === 'rejected' && $actor && ($actor->role ?? 'client') === 'client') {
            $this->dispatch('toast', message: 'Clients cannot reject approvals', type: 'error');
            return;
        }
        $pending = DB::table('approvals')->where('status', 'pending')->pluck('id')->toArray();
        $this->reasonTargets = array_values(array_intersect($this->selectedItems, $pending));
        if (empty($this->reasonTargets)) {
            $this->dispatch('toast', message: 'No pending items selected', type: 'warning');
            return;
        }
        $this->reasonAction = $action;
        $this->reasonText = '';
        $this->showReasonModal = true;
    }

    public function submitReason(): void
    {
        if (!trim($this->reasonText)) {
            $this->dispatch('toast', message: 'Reason is required', type: 'error');
            return;
        }
        $actor = Auth::user() ?? Auth::guard('client')->user();
        if ($this->reasonAction === 'rejected' && $actor && ($actor->role ?? 'client') === 'client') {
            $this->dispatch('toast', message: 'Clients cannot reject approvals', type: 'error');
            return;
        }
        $count = 0;
        foreach ($this->reasonTargets as $id) {
            if ($this->applyStatus($id, $this->reasonAction, trim($this->reasonText), suppressSideEffects: true)) $count++;
        }
        if ($count > 0) {
            $isStaff = $actor && ($actor->role ?? 'client') !== 'client';
            app(NotificationService::class)->sendNotification(
                text: "{$count} approval(s) {$this->reasonAction} by {$actor?->name}",
                type: $this->reasonAction === 'approved' ? 'success' : ($this->reasonAction === 'rejected' ? 'error' : 'info'),
                link: route('approvals', absolute: false),
                forRole: $isStaff ? 'client' : 'manager',
            );
            $this->dispatch('toast', message: "{$count} approval(s) {$this->reasonAction}", type: 'success');
        }
        $this->selectedItems = array_values(array_diff($this->selectedItems, $this->reasonTargets));
        $this->showReasonModal = false;
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
                @if($this->isManager)
                <button wire:click="create" class="btn btn-primary btn-sm"><i class="fas fa-plus text-xs"></i> New Approval</button>
                @endif
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3" aria-live="polite">
                @foreach(['pending'=>'Pending','approved'=>'Approved','revision'=>'Revision','rejected'=>'Rejected'] as $k=>$label)
                <button wire:click="toggleStatusFilter('{{ $k }}')" class="stat-card text-center py-3 text-left transition-all {{ $statusFilter === $k ? 'ring-2 ring-[var(--brand)]' : '' }}">
                    <p class="stat-value text-xl font-extrabold {{ $k==='pending'?'text-amber-600':($k==='approved'?'text-green-600':($k==='rejected'?'text-red-600':'text-orange-600')) }}">{{ $this->stats[$k] }}</p>
                    <p class="stat-label text-[11px]">{{ $label }}</p>
                </button>
                @endforeach
            </div>

            {{-- Filters --}}
            <div class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="form-label">Search</label>
                    <div class="relative">
                        <input type="text" wire:model.live.debounce.250ms="search" placeholder="Search approvals..." class="form-input pl-10 w-full sm:w-56 focus:ring-0" />
                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    </div>
                </div>
                <div><label class="form-label">Type</label><select wire:model.live="typeFilter" class="form-select w-auto"><option value="">All Types</option><option value="post">Post</option><option value="reel">Reel</option><option value="story">Story</option><option value="video">Video</option><option value="carousel">Carousel</option><option value="blog">Blog</option></select></div>
                <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select w-auto"><option value="">All Status</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="revision">Revision</option><option value="rejected">Rejected</option></select></div>
                <div><label class="form-label">Client</label><select wire:model.live="clientFilter" class="form-select w-auto"><option value="">All Clients</option>@foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                @if($this->isManager && count($selectedItems) > 0)
                <div class="flex gap-2">
                    <button wire:click="bulkApprove" class="btn btn-success btn-sm" wire:loading.attr="disabled"><i class="fas fa-check-double text-xs"></i> Approve ({{ count($selectedItems) }})</button>
                    <button wire:click="openBulkReasonModal('revision')" class="btn btn-secondary btn-sm"><i class="fas fa-pen text-xs"></i> Revision</button>
                    <button wire:click="openBulkReasonModal('rejected')" class="btn btn-danger btn-sm"><i class="fas fa-times text-xs"></i> Reject</button>
                    <button wire:click="deselectAll" class="btn btn-ghost btn-sm">Deselect</button>
                </div>
                @endif
            </div>

            {{-- Approval Cards --}}
            <div class="space-y-3">
                @forelse($this->approvals as $a)
                <div class="bg-white rounded-2xl border border-gray-100 p-4 hover:shadow-md transition-shadow" role="button" tabindex="0" wire:click="openDetail({{ $a->id }})" x-on:keydown.enter="$wire.openDetail({{ $a->id }})">
                    <div class="flex items-start gap-4">
                        @if($this->isManager && $a->status === 'pending')
                        <input type="checkbox" wire:change.stop="toggleSelect({{ $a->id }})" {{ in_array($a->id, $selectedItems) ? 'checked' : '' }} class="mt-1 h-4 w-4 rounded border-gray-300 text-[var(--brand)]">
                        @endif
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)] shrink-0">
                            <i class="fas fa-check-double text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="text-sm font-bold text-gray-900">{{ $a->title ?? 'Untitled' }}</h4>
                                <span class="badge badge-{{ $a->status }}">{{ ucfirst($a->status) }}</span>
                                @if($a->type)<span class="badge badge-{{ $a->type }}">{{ ucfirst($a->type) }}</span>@endif
                            </div>
                            <p class="text-xs text-gray-500">Client: {{ $a->client_name ?? '—' }} · Submitted by: {{ $a->submitter_name ?? '—' }} · {{ $a->created_at ? \Carbon\Carbon::parse($a->created_at)->diffForHumans() : '' }}</p>
                            @if($a->notes)<p class="text-sm text-gray-600 mt-1">{{ Str::limit($a->notes, 120) }}</p>@endif

                            @if($a->reference_file)
                            <div class="mt-2">
                                @if($this->isImage($a->reference_file))
                                <img src="{{ $this->fileUrl($a->reference_file) }}" alt="Reference file" class="h-20 rounded-lg object-cover border border-gray-200" loading="lazy" />
                                @else
                                <span class="inline-flex items-center gap-1 text-xs text-[var(--brand)]"><i class="fas fa-paperclip"></i> Attachment</span>
                                @endif
                            </div>
                            @endif

                            <div class="flex items-center gap-2 mt-3" x-on:click.stop>
                                @if($a->status === 'pending')
                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve this item?', message: 'This will mark the item as approved.', type: 'info', action: 'updateStatus', params: [{{ $a->id }}, 'approved'] })" class="btn btn-success btn-sm"><i class="fas fa-check text-xs"></i> Approve</button>
                                <button type="button" wire:click="openReasonModal({{ $a->id }}, 'revision')" class="btn btn-secondary btn-sm"><i class="fas fa-pen text-xs"></i> Revision</button>
                                @if($this->isManager)
                                <button type="button" wire:click="openReasonModal({{ $a->id }}, 'rejected')" class="btn btn-danger btn-sm"><i class="fas fa-times text-xs"></i> Reject</button>
                                @endif
                                @elseif($a->status === 'revision')
                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve this item?', message: 'This will mark the item as approved.', type: 'info', action: 'updateStatus', params: [{{ $a->id }}, 'approved'] })" class="btn btn-success btn-sm"><i class="fas fa-check text-xs"></i> Approve</button>
                                @if($this->isManager)
                                <button type="button" wire:click="openReasonModal({{ $a->id }}, 'rejected')" class="btn btn-danger btn-sm"><i class="fas fa-times text-xs"></i> Reject</button>
                                @endif
                                @endif
                                @php $cc = $this->commentCounts[$a->id] ?? 0; @endphp
                                <button wire:click="openDetail({{ $a->id }})" class="btn btn-ghost btn-sm"><i class="fas fa-comments text-xs"></i> @if($cc > 0)<span class="badge badge-pending">{{ $cc }}</span> @endif</button>
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
                <div class="modal-box max-w-3xl">
                    <div class="modal-header">
                        <h3 class="text-base font-bold text-gray-900">{{ $appr->title ?? 'Approval Details' }}</h3>
                        <div class="flex items-center gap-2">
                            @if($this->isManager && in_array($appr->status ?? '', ['pending', 'revision']))
                            <button wire:click="$set('showDetail',false); edit({{ $appr->id }})" class="btn btn-secondary btn-sm"><i class="fas fa-pen text-xs"></i> Edit</button>
                            @endif
                            <button wire:click="$set('showDetail',false)" class="btn btn-ghost btn-icon btn-sm" aria-label="Close"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div class="modal-body space-y-4">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {{-- Preview region --}}
                            <div class="space-y-3">
                                @if($appr->reference_file)
                                <div>
                                    @if($this->isImage($appr->reference_file))
                                    <img src="{{ $this->fileUrl($appr->reference_file) }}" alt="Reference file" class="w-full rounded-xl object-contain border border-gray-200 max-h-80" loading="lazy" />
                                    @else
                                    <a href="{{ $this->fileUrl($appr->reference_file) }}" target="_blank" class="inline-flex items-center gap-1 text-sm text-[var(--brand)] hover:underline"><i class="fas fa-paperclip"></i> View attachment</a>
                                    @endif
                                </div>
                                @endif
                                @if($appr->notes)<div><h4 class="text-sm font-semibold text-gray-900 mb-1">Notes</h4><p class="text-sm text-gray-600">{{ $appr->notes }}</p></div>@endif
                                <div class="grid grid-cols-2 gap-3 text-sm">
                                    <div><span class="text-gray-500">Status:</span> <span class="badge badge-{{ $appr->status ?? '' }}">{{ ucfirst($appr->status ?? '') }}</span></div>
                                    <div><span class="text-gray-500">Type:</span> {{ ucfirst($appr->type ?? '—') }}</div>
                                    <div><span class="text-gray-500">Client:</span> {{ $appr->client_name ?? '—' }}</div>
                                    <div><span class="text-gray-500">Submitted by:</span> {{ $appr->submitter_name ?? '—' }}</div>
                                    <div class="col-span-2"><span class="text-gray-500">Date:</span> {{ $appr->created_at ? \Carbon\Carbon::parse($appr->created_at)->format('M d, Y') : '—' }}</div>
                                </div>
                            </div>

                            {{-- Thread region --}}
                            <div class="space-y-3">
                                @if(in_array($appr->status ?? '', ['approved','rejected']))
                                <div class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-sm text-amber-700">
                                    <i class="fas fa-lock mr-1"></i> Decision finalized — no further actions can be taken.
                                </div>
                                @endif

                                @if($appr->status === 'pending')
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve this item?', message: 'This will mark the item as approved.', type: 'info', action: 'updateStatus', params: [{{ $appr->id }}, 'approved'] })" class="btn btn-success btn-sm"><i class="fas fa-check text-xs"></i> Approve</button>
                                    <button type="button" wire:click="openReasonModal({{ $appr->id }}, 'revision')" class="btn btn-secondary btn-sm"><i class="fas fa-pen text-xs"></i> Request Revision</button>
                                    @if($this->isManager)
                                    <button type="button" wire:click="openReasonModal({{ $appr->id }}, 'rejected')" class="btn btn-danger btn-sm"><i class="fas fa-times text-xs"></i> Reject</button>
                                    @endif
                                </div>
                                @elseif($appr->status === 'revision')
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve this item?', message: 'This will mark the item as approved.', type: 'info', action: 'updateStatus', params: [{{ $appr->id }}, 'approved'] })" class="btn btn-success btn-sm"><i class="fas fa-check text-xs"></i> Approve</button>
                                    @if($this->isManager)
                                    <button type="button" wire:click="openReasonModal({{ $appr->id }}, 'rejected')" class="btn btn-danger btn-sm"><i class="fas fa-times text-xs"></i> Reject</button>
                                    @endif
                                </div>
                                @endif

                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900 mb-2">Comments</h4>
                                    <div class="space-y-2 max-h-60 overflow-y-auto">
                                        @forelse($comments as $c)
                                        <div class="{{ $c->is_system ? 'bg-blue-50' : 'bg-gray-50' }} rounded-lg px-3 py-2">
                                            <p class="text-sm font-medium {{ $c->is_system ? 'text-blue-700' : 'text-gray-900' }}">{{ $c->user_name }} <span class="text-[10px] text-gray-400 font-normal">{{ \Carbon\Carbon::parse($c->created_at)->diffForHumans() }}</span></p>
                                            <p class="text-sm {{ $c->is_system ? 'text-blue-600 italic' : 'text-gray-600' }}">{{ $c->text }}</p>
                                        </div>
                                        @empty <p class="text-xs text-gray-400">No comments yet</p> @endforelse
                                    </div>
                                    <div class="flex gap-2 mt-3"><textarea wire:model="commentText" class="form-input flex-1" rows="2" placeholder="Add a comment..."></textarea><button wire:click="addComment" class="btn btn-primary btn-sm self-end"><i class="fas fa-paper-plane text-xs"></i></button></div>
                                </div>

                                @if($this->isManager)
                                <div class="pt-2 border-t border-gray-100">
                                    <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Approval?', message: 'This approval and its comments will be removed.', type: 'danger', action: 'deleteApproval', params: [{{ $appr->id }}] })" class="btn btn-ghost btn-sm text-red-500" aria-label="Delete approval"><i class="fas fa-trash text-xs"></i> Delete</button>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Create / Edit Form Modal --}}
            @if($showForm && $this->isManager)
            <div class="modal-overlay" wire:click.self="$set('showForm',false)" x-on:keydown.escape.window="$wire.set('showForm',false)">
                <div class="modal-box max-w-lg">
                    <div class="modal-header"><h3 class="text-base font-bold text-gray-900">{{ $editingId > 0 ? 'Edit Approval' : 'New Approval' }}</h3><button wire:click="$set('showForm',false)" class="btn btn-ghost btn-icon btn-sm" aria-label="Close"><i class="fas fa-times"></i></button></div>
                    <div class="modal-body space-y-4">
                        <div><label class="form-label">Title</label><input type="text" wire:model="formTitle" class="form-input" placeholder="Approval title" /></div>
                        <div><label class="form-label">Type</label><select wire:model="formType" class="form-select"><option value="post">Post</option><option value="reel">Reel</option><option value="story">Story</option><option value="video">Video</option><option value="carousel">Carousel</option><option value="blog">Blog</option></select></div>
                        <div><label class="form-label">Client</label><select wire:model="formClientId" class="form-select"><option value="0">Internal / Own Company</option>@foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                        @if ($formClientId)
                            <div><label class="form-label">Link to Content <span class="text-gray-400 text-xs">(optional)</span></label><select wire:model="formContentId" class="form-select"><option value="">No linked content</option>@foreach($this->getAvailableContent() as $content)<option value="{{ $content->id }}">{{ $content->title }} — {{ \Carbon\Carbon::parse($content->date)->format('M j') }} ({{ ucfirst($content->platform) }})</option>@endforeach</select></div>
                        @endif
                        <div><label class="form-label">Notes</label><textarea wire:model="formNotes" class="form-textarea" rows="3" placeholder="Notes..."></textarea></div>
                        <div><label class="form-label">Reference File</label><input type="text" wire:model="formReferenceFile" class="form-input" placeholder="Path or URL" /></div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button wire:click="$set('showForm',false)" class="btn btn-secondary btn-sm">Cancel</button>
                            <button wire:click="createOrUpdate" class="btn btn-primary btn-sm" wire:loading.attr="disabled"><i class="fas fa-save text-xs"></i> Save</button>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Reason Modal --}}
            @if($showReasonModal)
            <div class="modal-overlay" wire:click.self="$set('showReasonModal',false)" x-on:keydown.escape.window="$wire.set('showReasonModal',false)">
                <div class="modal-box max-w-md">
                    <div class="modal-header">
                        <h3 class="text-base font-bold text-gray-900">{{ $reasonAction === 'rejected' ? 'Reject' : 'Request Revision' }}</h3>
                        <button wire:click="$set('showReasonModal',false)" class="btn btn-ghost btn-icon btn-sm" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="modal-body space-y-4">
                        <p class="text-sm text-gray-500">{{ $reasonAction === 'rejected' ? 'Please provide a reason for rejection. This will be visible to the submitter.' : 'What needs to be changed? This feedback will be visible to the submitter.' }}</p>
                        <div>
                            <label class="form-label">Feedback</label>
                            <textarea wire:model="reasonText" class="form-textarea" rows="4" placeholder="{{ $reasonAction === 'rejected' ? 'e.g. Does not meet brand guidelines...' : 'e.g. Please update the caption and resubmit...' }}"></textarea>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button wire:click="$set('showReasonModal',false)" class="btn btn-secondary btn-sm">Cancel</button>
                            <button wire:click="submitReason" class="btn {{ $reasonAction === 'rejected' ? 'btn-danger' : 'btn-secondary' }} btn-sm" wire:loading.attr="disabled"><i class="fas fa-paper-plane text-xs"></i> Submit</button>
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
