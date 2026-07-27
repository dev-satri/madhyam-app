<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Complaint;
use App\Support\UserVisibility;
use Illuminate\Support\Facades\Storage;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination, WithFileUploads;

    public string $tabFilter = 'all';
    public string $search = '';
    public bool $showForm = false;
    public int $editingId = 0;

    // Detail modal
    public bool $showDetail = false;
    public int $detailId = 0;
    public ?stdClass $currentComplaint = null;
    public $replies = [];
    public string $replyText = '';
    public $replyFile = null;

    // Progress modal
    public bool $showProgressModal = false;
    public int $currentActionId = 0;
    public string $progressNotes = '';

    // Resolve modal
    public bool $showResolveModal = false;
    public string $resolveNotes = '';

    // Edit form
    public string $formTitle = '';
    public string $formDescription = '';
    public int $formClientId = 0;
    public int $formAssignedTo = 0;
    public string $formPriority = 'medium';

    public function isClient(): bool
    {
        return Auth::guard('client')->check();
    }

    public function isManager(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        return in_array($user->role, ['super-admin', 'admin', 'manager']);
    }

    public function canClientEdit(string $status): bool
    {
        if (!$this->isClient()) return false;
        return in_array($status, ['open']);
    }

    public function getStats(): array
    {
        $q = DB::table('complaints');
        if ($this->isClient()) {
            // Auth::guard('client')->id() returns the client_accounts.id PK,
            // NOT the clients.id FK stored on complaints.client_id. Use ->user()->client_id.
            $q->where('client_id', Auth::guard('client')->user()->client_id);
        }

        return [
            'open' => (clone $q)->where('status', 'open')->count(),
            'in_progress' => (clone $q)->where('status', 'in-progress')->count(),
            'resolved' => (clone $q)->where('status', 'resolved')->count(),
            'total' => (clone $q)->count(),
        ];
    }

    public function getComplaints()
    {
        $q = DB::table('complaints')
            ->join('clients', 'complaints.client_id', '=', 'clients.id')
            ->leftJoin('users', 'complaints.assigned_to', '=', 'users.id');

        if ($this->isClient()) {
            // client_accounts.id !== clients.id. complaints.client_id references clients.id.
            $q->where('complaints.client_id', Auth::guard('client')->user()->client_id);
        }

        if ($this->tabFilter !== 'all') {
            $q->where('complaints.status', $this->tabFilter === 'in-progress' ? 'in-progress' : $this->tabFilter);
        }

        if ($this->search) {
            $q->where(function ($sub) {
                $sub->where('complaints.title', 'like', "%{$this->search}%")
                    ->orWhere('clients.name', 'like', "%{$this->search}%");
            });
        }

        return $q->select('complaints.*', 'clients.name as client_name', 'users.name as assignee_name')
            ->orderBy('complaints.created_at', 'desc')
            ->paginate(50);
    }

    #[Computed]
    public function complaints() { return $this->getComplaints(); }

    #[Computed]
    public function clients()
    {
        return DB::table('clients')->orderBy('name')->get();
    }

    #[Computed]
    public function team()
    {
        return UserVisibility::apply(
            DB::table('users')->where('status', 'active')
        )->orderBy('name')->get();
    }

    #[Computed]
    public function isClientUser(): bool { return $this->isClient(); }

    #[Computed]
    public function isManagerUser(): bool { return $this->isManager(); }

    #[Computed]
    public function stats(): array { return $this->getStats(); }

    // ─── Detail Modal ──────────────────────────────────────────────

    public function openDetail(int $id): void
    {
        $row = DB::table('complaints')
            ->join('clients', 'complaints.client_id', '=', 'clients.id')
            ->leftJoin('users', 'complaints.assigned_to', '=', 'users.id')
            ->where('complaints.id', $id)
            ->select('complaints.*', 'clients.name as client_name', 'users.name as assignee_name')
            ->first();

        if (!$row) return;

        $this->currentComplaint = (array) $row ? (object) (array) $row : null;
        $this->detailId = $id;
        $this->replies = DB::table('complaint_replies')
            ->leftJoin('users', 'complaint_replies.user_id', '=', 'users.id')
            ->where('complaint_replies.complaint_id', $id)
            ->select('complaint_replies.*', 'users.name as user_display_name')
            ->orderBy('complaint_replies.created_at')
            ->get()
            ->toArray();
        $this->replyText = '';
        $this->replyFile = null;
        $this->showDetail = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detailId = 0;
        $this->currentComplaint = null;
        $this->replies = [];
    }

    // ─── Form (Create / Edit) ──────────────────────────────────────

    public function openForm(?int $id = null): void
    {
        if ($id) {
            $c = DB::table('complaints')->where('id', $id)->first();
            if ($c) {
                $this->editingId = $id;
                $this->formTitle = $c->title;
                $this->formDescription = $c->description;
                $this->formClientId = $c->client_id;
                $this->formAssignedTo = $c->assigned_to ?? 0;
                $this->formPriority = $c->priority;
            }
        } else {
            $this->editingId = 0;
            $this->formTitle = '';
            $this->formDescription = '';
            $this->formClientId = $this->isClient() ? Auth::guard('client')->user()->client_id : 0;
            $this->formAssignedTo = 0;
            $this->formPriority = 'medium';
        }
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'formTitle' => 'required|string|max:255',
            'formDescription' => 'required|string',
        ]);

        $data = [
            'title' => $this->formTitle,
            'description' => $this->formDescription,
            'client_id' => $this->formClientId ?: ($this->isClient() ? Auth::guard('client')->user()->client_id : null),
            'assigned_to' => $this->formAssignedTo ?: null,
            'priority' => $this->formPriority,
        ];

        if ($this->editingId) {
            DB::table('complaints')->where('id', $this->editingId)->update($data + ['updated_at' => now()]);
            $msg = 'Complaint updated';
        } else {
            $data['status'] = 'open';
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('complaints')->insert($data);
            $msg = 'Complaint created';
        }

        $this->showForm = false;
        $this->editingId = 0;

        if ($this->showDetail && $this->detailId) {
            $this->openDetail($this->detailId);
        }

        $this->dispatch('toast', message: $msg, type: 'success');
    }

    // ─── Status Transitions ────────────────────────────────────────

    public function openProgressModal(int $id): void
    {
        $this->currentActionId = $id;
        $this->progressNotes = '';
        $this->showProgressModal = true;
    }

    public function confirmMarkInProgress(): void
    {
        $c = DB::table('complaints')->where('id', $this->currentActionId)->first();
        if (!$c || $c->status !== 'open') {
            $this->dispatch('toast', message: 'Complaint is not in open status', type: 'error');
            return;
        }

        DB::table('complaints')->where('id', $this->currentActionId)->update([
            'status' => 'in-progress',
            'status_notes' => $this->progressNotes ?: null,
            'updated_at' => now(),
        ]);

        $this->showProgressModal = false;
        $this->dispatch('toast', message: 'Status changed to In Progress', type: 'success');

        if ($this->showDetail && $this->detailId) {
            $this->openDetail($this->detailId);
        }
    }

    public function openResolveModal(int $id): void
    {
        $this->currentActionId = $id;
        $this->resolveNotes = '';
        $this->showResolveModal = true;
    }

    public function confirmResolve(): void
    {
        $this->validate(['resolveNotes' => 'required|string|min:5']);

        $c = DB::table('complaints')->where('id', $this->currentActionId)->first();
        if (!$c || $c->status !== 'in-progress') {
            $this->dispatch('toast', message: 'Complaint must be in-progress to resolve', type: 'error');
            return;
        }

        DB::table('complaints')->where('id', $this->currentActionId)->update([
            'status' => 'resolved',
            'resolution_notes' => $this->resolveNotes,
            'status_notes' => null,
            'updated_at' => now(),
        ]);

        $this->showResolveModal = false;
        $this->dispatch('toast', message: 'Complaint resolved', type: 'success');

        if ($this->showDetail && $this->detailId) {
            $this->openDetail($this->detailId);
        }
    }

    public function reopenComplaint(int $id): void
    {
        if (!$this->isManager()) return;

        $c = DB::table('complaints')->where('id', $id)->first();
        if (!$c || $c->status !== 'resolved') return;

        DB::table('complaints')->where('id', $id)->update([
            'status' => 'open',
            'resolution_notes' => null,
            'status_notes' => null,
            'updated_at' => now(),
        ]);

        $this->dispatch('toast', message: 'Complaint reopened', type: 'success');

        if ($this->showDetail && $this->detailId) {
            $this->openDetail($this->detailId);
        }
    }

    // ─── Delete ────────────────────────────────────────────────────

    public function deleteComplaint(int $id): void
    {
        $complaint = Complaint::findOrFail($id);
        if ($complaint->status === 'in-progress') {
            $this->dispatch('toast', message: 'Cannot delete a complaint that is in progress', type: 'error');
            return;
        }
        $complaint->delete();
        $this->closeDetail();
        $this->dispatch('toast', message: 'Complaint deleted', type: 'success');
    }

    // ─── Replies ───────────────────────────────────────────────────

    public function sendReply(int $complaintId): void
    {
        $this->validate(['replyText' => 'required_without:replyFile|string|max:2000']);

        $user = Auth::user() ?? Auth::guard('client')->user();
        if (!$user) return;

        $filePath = null;
        if ($this->replyFile) {
            $filePath = $this->replyFile->store('complaints/' . now()->format('Y/m'), 'public');
        }

        DB::table('complaint_replies')->insert([
            'complaint_id' => $complaintId,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'text' => $this->replyText ?: null,
            'file_path' => $filePath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('complaints')->where('id', $complaintId)->update(['updated_at' => now()]);

        $this->replyText = '';
        $this->replyFile = null;
        $this->openDetail($complaintId);
        $this->dispatch('toast', message: 'Reply sent', type: 'success');
    }

    public function removeReplyFile(): void
    {
        $this->replyFile = null;
    }

    // ─── Helpers ───────────────────────────────────────────────────

    public function fileUrl(?string $path): string
    {
        if (!$path) return '';
        if (str_starts_with($path, 'http')) return $path;
        return Storage::url($path);
    }

    public function isImage(?string $path): bool
    {
        if (!$path) return false;
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|svg|bmp|ico)$/i', $path);
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Complaints</h1>
                    <p class="text-sm text-gray-500 mt-1">{{ $this->isClientUser ? 'Track your support requests' : 'Manage client complaints and feedback' }}</p>
                </div>
                <button wire:click="openForm" class="btn btn-primary">
                    <i class="fas fa-plus text-sm"></i>
                    {{ $this->isClientUser ? 'Submit Complaint' : 'New Complaint' }}
                </button>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-layer-group"></i></div><div class="stat-value">{{ $this->stats['total'] }}</div><div class="stat-label">Total</div></div>
                <div class="stat-card"><div class="stat-icon bg-red-100 text-red-600"><i class="fas fa-exclamation-circle"></i></div><div class="stat-value">{{ $this->stats['open'] }}</div><div class="stat-label">Open</div></div>
                <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-spinner"></i></div><div class="stat-value">{{ $this->stats['in_progress'] }}</div><div class="stat-label">In Progress</div></div>
                <div class="stat-card"><div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-check-circle"></i></div><div class="stat-value">{{ $this->stats['resolved'] }}</div><div class="stat-label">Resolved</div></div>
            </div>

            {{-- Tabs + Search --}}
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="tab-group mb-0 flex-1">
                    <button wire:click="$set('tabFilter', 'all')" class="tab-btn {{ $tabFilter === 'all' ? 'active' : '' }}">All</button>
                    <button wire:click="$set('tabFilter', 'open')" class="tab-btn {{ $tabFilter === 'open' ? 'active' : '' }}">Open</button>
                    <button wire:click="$set('tabFilter', 'in-progress')" class="tab-btn {{ $tabFilter === 'in-progress' ? 'active' : '' }}">In Progress</button>
                    <button wire:click="$set('tabFilter', 'resolved')" class="tab-btn {{ $tabFilter === 'resolved' ? 'active' : '' }}">Resolved</button>
                </div>
                <div class="w-full sm:w-72">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                        <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search complaints..." class="form-input pl-10 focus:ring-0">
                    </div>
                </div>
            </div>

            {{-- Complaint Cards --}}
            <div class="space-y-3">
                @forelse($this->complaints as $c)
                    @php
                        $statusDot = match($c->status) { 'open' => 'bg-red-500', 'in-progress' => 'bg-amber-500', 'resolved' => 'bg-green-500', default => 'bg-gray-400' };
                        $priorityBadge = match($c->priority) { 'urgent' => 'badge-urgent', 'high' => 'badge-high', 'medium' => 'badge-medium', 'low' => 'badge-low', default => '' };
                        $statusRing = match($c->status) { 'open' => 'ring-red-100', 'in-progress' => 'ring-amber-100', 'resolved' => 'ring-green-100', default => '' };
                        $replyCount = DB::table('complaint_replies')->where('complaint_id', $c->id)->count();
                        $hasFiles = DB::table('complaint_replies')->where('complaint_id', $c->id)->whereNotNull('file_path')->exists();
                        // Primary action (single, contextual)
                        $primary = null;
                        if ($c->status === 'open' && !$this->isClientUser) {
                            $primary = ['label' => 'Start', 'icon' => 'fa-play', 'class' => 'btn-primary', 'method' => 'openProgressModal'];
                        } elseif ($c->status === 'in-progress' && !$this->isClientUser) {
                            $primary = ['label' => 'Resolve', 'icon' => 'fa-check', 'class' => 'btn-success', 'method' => 'openResolveModal'];
                        } elseif ($c->status === 'resolved' && $this->isManagerUser) {
                            $primary = ['label' => 'Reopen', 'icon' => 'fa-undo', 'class' => 'btn-warning', 'method' => 'reopenComplaint'];
                        }
                        $canEdit = $this->canClientEdit($c->status);
                    @endphp
                    <div wire:click="openDetail({{ $c->id }})"
                         class="bg-white border border-gray-100 rounded-xl p-4 cursor-pointer hover:border-[var(--brand)] hover:shadow-md transition-all duration-150">
                        {{-- Row 1: dot + title + badges + action bar --}}
                        <div class="flex items-start gap-3">
                            <div class="w-2.5 h-2.5 rounded-full {{ $statusDot }} mt-2 flex-shrink-0 ring-4 {{ $statusRing }}"></div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="font-bold text-sm text-gray-900 break-words">{{ $c->title }}</h3>
                                    <span class="badge {{ $priorityBadge }}">{{ ucfirst($c->priority) }}</span>
                                    <span class="badge badge-{{ $c->status }}">{{ ucfirst(str_replace('-', ' ', $c->status)) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0" x-data="{ open: false }" x-on:click.stop>
                                @if($primary)
                                    <button wire:click.stop="{{ $primary['method'] }}({{ $c->id }})" class="btn btn-sm {{ $primary['class'] }} px-3 py-1 text-xs">
                                        <i class="fas {{ $primary['icon'] }} text-[10px] mr-1"></i>{{ $primary['label'] }}
                                    </button>
                                @endif
                                @if($canEdit)
                                    <div class="relative">
                                        <button type="button" x-on:click="open = !open" class="btn btn-ghost btn-icon btn-sm" aria-label="More actions" title="More">
                                            <i class="fas fa-ellipsis-h text-xs text-gray-500"></i>
                                        </button>
                                        <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition class="absolute right-0 mt-1 w-40 bg-white border border-gray-100 rounded-lg shadow-lg z-20 py-1">
                                            <button type="button" wire:click.stop="openForm({{ $c->id }})" x-on:click="open = false" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                                <i class="fas fa-pen text-xs text-gray-400"></i> Edit
                                            </button>
                                        </div>
                                    </div>
                                @endif
                                <i class="fas fa-chevron-right text-xs text-gray-300 ml-1"></i>
                            </div>
                        </div>

                        {{-- Row 2: description --}}
                        <p class="text-sm text-gray-600 mt-2 line-clamp-2 pl-[22px]">{{ $c->description }}</p>

                        {{-- Row 3: meta chips (wrap freely on any width) --}}
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 mt-2.5 pl-[22px] text-xs text-gray-500">
                            <span class="inline-flex items-center gap-1"><i class="fas fa-user text-gray-300"></i>{{ $c->client_name }}</span>
                            @if($c->assignee_name)
                                <span class="inline-flex items-center gap-1"><i class="fas fa-user-tag text-gray-300"></i>{{ $c->assignee_name }}</span>
                            @endif
                            <span class="inline-flex items-center gap-1"><i class="fas fa-clock text-gray-300"></i>{{ fmtDateTime($c->created_at) }}</span>
                            @if($replyCount > 0)
                                <span class="inline-flex items-center gap-1"><i class="fas fa-comment text-gray-300"></i>{{ $replyCount }} {{ $replyCount === 1 ? 'reply' : 'replies' }}</span>
                            @endif
                            @if($hasFiles)
                                <span class="inline-flex items-center gap-1"><i class="fas fa-paperclip text-gray-300"></i>attached</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="bg-white rounded-2xl border border-gray-100 p-8 text-center">
                        <i class="fas fa-inbox text-4xl text-gray-200 mb-3"></i>
                        <p class="text-gray-400 text-sm">No complaints found</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-4">{{ $this->complaints->links() }}</div>

            {{-- ─── DETAIL MODAL ─────────────────────────────────── --}}
            @if($showDetail && $currentComplaint)
                @php
                    $detailStatusDot = match($currentComplaint->status) { 'open' => 'bg-red-500', 'in-progress' => 'bg-amber-500', 'resolved' => 'bg-green-500', default => '' };
                @endphp
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
                     wire:click.self="closeDetail"
                     x-on:keydown.escape.window="$wire.closeDetail()">
                    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col">
                        {{-- Header --}}
                        <div class="sticky top-0 bg-white border-b px-6 py-4 rounded-t-2xl flex items-center justify-between z-10">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-3 h-3 rounded-full flex-shrink-0 {{ $detailStatusDot }}"></div>
                                <h2 class="font-bold text-lg text-gray-900 truncate">{{ $currentComplaint->title }}</h2>
                                <span class="badge badge-{{ $currentComplaint->priority }}">{{ ucfirst($currentComplaint->priority) }}</span>
                                <span class="badge badge-{{ $currentComplaint->status }}">{{ ucfirst(str_replace('-', ' ', $currentComplaint->status)) }}</span>
                            </div>
                            <button wire:click="closeDetail" class="text-gray-400 hover:text-gray-600 ml-4"><i class="fas fa-times text-lg"></i></button>
                        </div>

                        {{-- Body --}}
                        <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                            {{-- Info Grid --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div><span class="text-gray-400 text-xs">Client</span><p class="font-medium">{{ $currentComplaint->client_name }}</p></div>
                                <div><span class="text-gray-400 text-xs">Assigned To</span><p class="font-medium">{{ $currentComplaint->assignee_name ?? 'Unassigned' }}</p></div>
                                <div><span class="text-gray-400 text-xs">Created</span><p class="font-medium">{{ fmtDateTime($currentComplaint->created_at) }}</p></div>
                                <div><span class="text-gray-400 text-xs">Updated</span><p class="font-medium">{{ fmtDateTime($currentComplaint->updated_at) }}</p></div>
                            </div>

                            {{-- Description --}}
                            <div>
                                <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Description</h4>
                                <p class="text-sm text-gray-700 bg-gray-50 rounded-xl p-4 whitespace-pre-wrap">{{ $currentComplaint->description }}</p>
                            </div>

                            {{-- Status Notes (when in-progress) --}}
                            @if($currentComplaint->status === 'in-progress' && $currentComplaint->status_notes)
                                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                                    <h4 class="text-xs font-semibold text-amber-700 uppercase tracking-wider mb-1"><i class="fas fa-info-circle mr-1"></i>Status Notes</h4>
                                    <p class="text-sm text-amber-800 whitespace-pre-wrap">{{ $currentComplaint->status_notes }}</p>
                                </div>
                            @endif

                            {{-- Resolution Notes (when resolved) --}}
                            @if($currentComplaint->status === 'resolved' && $currentComplaint->resolution_notes)
                                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                                    <h4 class="text-xs font-semibold text-green-700 uppercase tracking-wider mb-1"><i class="fas fa-check-circle mr-1"></i>Resolution Notes</h4>
                                    <p class="text-sm text-green-800 whitespace-pre-wrap">{{ $currentComplaint->resolution_notes }}</p>
                                </div>
                            @endif

                            {{-- Reply Thread --}}
                            <div>
                                <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Replies</h4>
                                <div class="space-y-3">
                                    @forelse($replies as $r)
                                        @php
                                            $initials = strtoupper(substr($r->user_name ?? 'U', 0, 2));
                                            $isMe = (Auth::id() && $r->user_id == Auth::id()) || (Auth::guard('client')->check() && $r->user_id == Auth::guard('client')->user()->id);
                                        @endphp
                                        <div class="flex gap-3">
                                            <div class="w-9 h-9 rounded-full {{ $isMe ? 'bg-[var(--brand)] text-white' : 'bg-gray-200 text-gray-600' }} flex items-center justify-center text-xs font-bold flex-shrink-0">{{ $initials }}</div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm font-semibold text-gray-900">{{ $r->user_name }}</span>
                                                    <span class="text-xs text-gray-400">{{ fmtDateTime($r->created_at) }}</span>
                                                </div>
                                                @if($r->text)
                                                    <p class="text-sm text-gray-700 mt-1 whitespace-pre-wrap">{{ $r->text }}</p>
                                                @endif
                                                @if($r->file_path)
                                                    <div class="mt-2">
                                                        @if($this->isImage($r->file_path))
                                                            <img src="{{ $this->fileUrl($r->file_path) }}" alt="Attachment" class="max-w-xs max-h-48 rounded-lg object-cover border border-gray-200 shadow-sm cursor-pointer hover:shadow-md transition-shadow" loading="lazy" />
                                                        @else
                                                            <a href="{{ $this->fileUrl($r->file_path) }}" target="_blank" class="inline-flex items-center gap-2 bg-gray-50 hover:bg-gray-100 rounded-lg px-3 py-2 text-sm text-[var(--brand)] transition-colors">
                                                                <i class="fas fa-file-alt"></i>
                                                                <span>{{ basename($r->file_path) }}</span>
                                                                <i class="fas fa-external-link-alt text-xs opacity-50"></i>
                                                            </a>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-center text-gray-400 text-sm py-4">No replies yet</p>
                                    @endforelse
                                </div>
                            </div>

                            {{-- Reply Form --}}
                            <div class="border-t pt-4">
                                <div class="flex gap-2">
                                    <div class="flex-1">
                                        <textarea wire:model="replyText" placeholder="Type a reply..." rows="2" class="form-input resize-none" wire:keydown.enter.prevent="sendReply({{ $detailId }})"></textarea>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="btn btn-ghost btn-sm cursor-pointer" title="Attach file">
                                            <i class="fas fa-paperclip text-gray-400 hover:text-[var(--brand)]"></i>
                                            <input type="file" wire:model="replyFile" class="hidden" accept="image/*,.pdf,.doc,.docx,.txt,.zip">
                                        </label>
                                        <button wire:click="sendReply({{ $detailId }})" class="btn btn-primary btn-sm" title="Send">
                                            <i class="fas fa-paper-plane text-sm"></i>
                                        </button>
                                    </div>
                                </div>
                                @if($replyFile)
                                    <div class="mt-2 flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2 text-sm">
                                        <i class="fas fa-file text-gray-400"></i>
                                        <span class="truncate">{{ $replyFile->getClientOriginalName() }}</span>
                                        <span class="text-xs text-gray-400">{{ round($replyFile->getSize() / 1024, 1) }} KB</span>
                                        <button wire:click="removeReplyFile" class="ml-auto text-gray-400 hover:text-red-500"><i class="fas fa-times"></i></button>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Action Bar --}}
                        <div class="sticky bottom-0 bg-white border-t px-6 py-3 rounded-b-2xl flex items-center gap-2 flex-wrap">
                            @if($this->isManagerUser)
                                <button wire:click="openForm({{ $detailId }})" class="btn btn-ghost btn-sm"><i class="fas fa-pen mr-1"></i> Edit</button>
                            @endif

                            @if($currentComplaint->status === 'open' && !$this->isClientUser)
                                <button wire:click="openProgressModal({{ $detailId }})" class="btn btn-primary btn-sm"><i class="fas fa-play mr-1"></i> Mark In Progress</button>
                            @endif

                            @if($currentComplaint->status === 'in-progress' && !$this->isClientUser)
                                <button wire:click="openResolveModal({{ $detailId }})" class="btn btn-success btn-sm"><i class="fas fa-check mr-1"></i> Resolve</button>
                            @endif

                            @if($currentComplaint->status === 'resolved' && $this->isManagerUser)
                                <button wire:click="reopenComplaint({{ $detailId }})" class="btn btn-warning btn-sm"><i class="fas fa-undo mr-1"></i> Reopen</button>
                            @endif

                            @if($this->canClientEdit($currentComplaint->status))
                                <button wire:click="openForm({{ $detailId }})" class="btn btn-primary btn-sm"><i class="fas fa-pen mr-1"></i> Edit Complaint</button>
                            @endif

                            @if($this->isManagerUser && $currentComplaint->status !== 'in-progress')
                                <div class="ml-auto">
                                    <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Complaint?', message: 'This complaint and all its replies will be permanently removed.', type: 'danger', action: 'deleteComplaint', params: [{{ $detailId }}] })" class="btn btn-ghost btn-sm text-red-500 hover:text-red-700 hover:bg-red-50">
                                        <i class="fas fa-trash mr-1"></i> Delete
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- ─── CREATE / EDIT FORM MODAL ─────────────────────── --}}
            @if($showForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
                     wire:click.self="$set('showForm', false)"
                     x-on:keydown.escape.window="$wire.set('showForm', false)">
                    <div class="modal-box w-full max-w-lg mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingId ? 'Edit' : 'New' }} Complaint</h3>
                            <button wire:click="$set('showForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div>
                                <label class="form-label">Title</label>
                                <input type="text" wire:model="formTitle" class="form-input" placeholder="Brief description of the issue">
                                <span wire:error="formTitle" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div>
                                <label class="form-label">Description</label>
                                <textarea wire:model="formDescription" class="form-input" rows="4" placeholder="Detailed description..."></textarea>
                                <span wire:error="formDescription" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @if(!$this->isClientUser)
                                    <div>
                                        <label class="form-label">Client</label>
                                        <select wire:model="formClientId" class="form-select">
                                            <option value="">Select client</option>
                                            @foreach($this->clients as $cl)
                                                <option value="{{ $cl->id }}">{{ $cl->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Assigned To</label>
                                        <select wire:model="formAssignedTo" class="form-select">
                                            <option value="">Unassigned</option>
                                            @foreach($this->team as $t)
                                                <option value="{{ $t->id }}">{{ $t->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                                <div>
                                    <label class="form-label">Priority</label>
                                    <select wire:model="formPriority" class="form-select">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">Save</span>
                                <span wire:loading wire:target="save" class="flex items-center gap-2">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ─── MARK IN PROGRESS MODAL ───────────────────── --}}
            @if($showProgressModal)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
                     wire:click.self="$set('showProgressModal', false)"
                     x-on:keydown.escape.window="$wire.set('showProgressModal', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg"><i class="fas fa-play-circle text-amber-500 mr-2"></i>Mark In Progress</h3>
                            <button wire:click="$set('showProgressModal', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <p class="text-sm text-gray-600">This complaint will be moved to <strong>In Progress</strong> status.</p>
                            <div>
                                <label class="form-label">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                                <textarea wire:model="progressNotes" class="form-input" rows="3" placeholder="What action are you taking?"></textarea>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showProgressModal', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="confirmMarkInProgress" class="btn btn-primary" wire:loading.attr="disabled" wire:target="confirmMarkInProgress">
                                <span wire:loading.remove wire:target="confirmMarkInProgress"><i class="fas fa-play mr-1"></i> Mark In Progress</span>
                                <span wire:loading wire:target="confirmMarkInProgress" class="flex items-center gap-2">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ─── RESOLVE MODAL ────────────────────────────── --}}
            @if($showResolveModal)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
                     wire:click.self="$set('showResolveModal', false)"
                     x-on:keydown.escape.window="$wire.set('showResolveModal', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg"><i class="fas fa-check-circle text-green-500 mr-2"></i>Resolve Complaint</h3>
                            <button wire:click="$set('showResolveModal', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <p class="text-sm text-gray-600">This complaint will be marked as <strong>Resolved</strong>.</p>
                            <div>
                                <label class="form-label">Resolution Notes <span class="text-red-500">*</span></label>
                                <textarea wire:model="resolveNotes" class="form-input" rows="3" placeholder="How was this issue resolved?"></textarea>
                                <span wire:error="resolveNotes" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showResolveModal', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="confirmResolve" class="btn btn-success" wire:loading.attr="disabled" wire:target="confirmResolve">
                                <span wire:loading.remove wire:target="confirmResolve"><i class="fas fa-check mr-1"></i> Resolve</span>
                                <span wire:loading wire:target="confirmResolve" class="flex items-center gap-2">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Resolving...
                                </span>
                            </button>
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
