<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $tabFilter = 'all';
    public string $search = '';
    public bool $showForm = false;
    public int $editingId = 0;
    public int $expandedComplaint = 0;

    // Form
    public string $formTitle = '';
    public string $formDescription = '';
    public int $formClientId = 0;
    public int $formAssignedTo = 0;
    public string $formPriority = 'medium';

    // Reply
    public int $replyComplaintId = 0;
    public string $replyText = '';

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

    public function getStats(): array
    {
        $q = DB::table('complaints');
        if ($this->isClient()) {
            $clientId = Auth::guard('client')->id();
            $q->where('client_id', $clientId);
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
            $q->where('complaints.client_id', Auth::guard('client')->id());
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

    public function getReplies(int $complaintId)
    {
        return DB::table('complaint_replies')
            ->leftJoin('users', 'complaint_replies.user_id', '=', 'users.id')
            ->where('complaint_replies.complaint_id', $complaintId)
            ->select('complaint_replies.*', 'users.name as user_display_name')
            ->orderBy('complaint_replies.created_at')
            ->get();
    }

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
            $this->formClientId = $this->isClient() ? Auth::guard('client')->id() : 0;
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
            'client_id' => $this->formClientId ?: ($this->isClient() ? Auth::guard('client')->id() : null),
            'assigned_to' => $this->formAssignedTo ?: null,
            'priority' => $this->formPriority,
        ];

        if ($this->editingId) {
            DB::table('complaints')->where('id', $this->editingId)->update($data);
        } else {
            $data['status'] = 'open';
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('complaints')->insert($data);
        }

        $this->showForm = false;
        $this->dispatch('toast', message: 'Complaint saved', type: 'success');
    }

    public function advanceStatus(int $id): void
    {
        $c = DB::table('complaints')->where('id', $id)->first();
        if (!$c) return;
        $next = match($c->status) {
            'open' => 'in-progress',
            'in-progress' => 'resolved',
            default => null,
        };
        if ($next) {
            DB::table('complaints')->where('id', $id)->update(['status' => $next, 'updated_at' => now()]);
            $this->dispatch('toast', message: "Status changed to $next", type: 'success');
        }
    }

    public function deleteComplaint(int $id): void
    {
        DB::table('complaints')->where('id', $id)->delete();
        $this->dispatch('toast', message: 'Complaint deleted', type: 'success');
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedComplaint = $this->expandedComplaint === $id ? 0 : $id;
    }

    public function sendReply(int $complaintId): void
    {
        $this->validate(['replyText' => 'required|string|max:1000']);

        $user = Auth::user();
        if (!$user) {
            $user = Auth::guard('client')->user();
        }
        if (!$user) return;

        DB::table('complaint_replies')->insert([
            'complaint_id' => $complaintId,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'text' => $this->replyText,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->replyText = '';
        $this->replyComplaintId = 0;
        $this->dispatch('toast', message: 'Reply sent', type: 'success');
    }

    #[Computed]
    public function stats(): array { return $this->getStats(); }

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
        return DB::table('users')->where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function isClientUser(): bool { return $this->isClient(); }

    #[Computed]
    public function isManagerUser(): bool { return $this->isManager(); }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Complaints</h1>
                    <p class="text-sm text-gray-500 mt-1">{{ $this->isClientUser ? 'Track your support requests' : 'Manage client complaints and feedback' }}</p>
                </div>
                @if(!$this->isClientUser)
                    <button wire:click="openForm" class="btn btn-primary"><i class="fas fa-plus text-sm"></i> New Complaint</button>
                @else
                    <button wire:click="openForm" class="btn btn-primary"><i class="fas fa-plus text-sm"></i> Submit Complaint</button>
                @endif
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
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
                <div class="w-full sm:w-72"><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="search" wire:model.live.debounce.250ms="search" placeholder="Search complaints..." class="form-input pl-10"></div></div>
            </div>

            {{-- Complaint Cards --}}
            <div class="space-y-4">
                @forelse($this->complaints as $c)
                    <div class="bg-white border border-gray-100 rounded-2xl overflow-hidden">
                        <div class="p-5">
                            <div class="flex items-start gap-4">
                                <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center {{ match($c->status) { 'open' => 'text-red-500', 'in-progress' => 'text-amber-500', 'resolved' => 'text-green-500', default => '' } }}">
                                    <i class="fas {{ match($c->status) { 'open' => 'fa-exclamation-circle', 'in-progress' => 'fa-spinner', 'resolved' => 'fa-check-circle', default => '' } }}"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="font-bold text-base">{{ $c->title }}</h3>
                                        <span class="badge {{ match($c->priority) { 'urgent' => 'badge-urgent', 'high' => 'badge-high', 'medium' => 'badge-medium', 'low' => 'badge-low', default => '' } }}">{{ ucfirst($c->priority) }}</span>
                                        <span class="badge badge-{{ $c->status }}">{{ ucfirst(str_replace('-', ' ', $c->status)) }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        {{ $c->client_name }} · {{ fmtDateTime($c->created_at) }}
                                        @if($c->assignee_name) · Assigned to {{ $c->assignee_name }} @endif
                                    </div>
                                    <p class="text-sm text-gray-600 mt-2">{{ $c->description }}</p>
                                </div>
                                <div class="flex gap-2 flex-shrink-0">
                                    @php $nextStatus = match($c->status) { 'open' => 'in-progress', 'in-progress' => 'resolved', default => null }; @endphp
                                    @if($nextStatus && !$this->isClientUser)
                                        <button wire:click="advanceStatus({{ $c->id }})" class="btn btn-sm {{ $c->status === 'open' ? 'btn-primary' : 'btn-success' }}">
                                            Mark {{ ucfirst(str_replace('-', ' ', $nextStatus)) }}
                                        </button>
                                    @endif
                                    @if(!$this->isClientUser)
                                        <button wire:click="openForm({{ $c->id }})" class="btn btn-icon btn-ghost" title="Edit"><i class="fas fa-pen text-gray-400 hover:text-[var(--brand)] text-xs"></i></button>
                                        <button wire:click="deleteComplaint({{ $c->id }})" wire:confirm="Are you sure you want to delete this complaint?" class="btn btn-icon btn-ghost" title="Delete"><i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i></button>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Reply Thread Toggle --}}
                        <div class="border-t px-5 py-2">
                            <button wire:click="toggleExpand({{ $c->id }})" class="text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1">
                                <i class="fas {{ $expandedComplaint === $c->id ? 'fa-chevron-up' : 'fa-chevron-down' }}"></i>
                                Replies
                            </button>
                        </div>

                        {{-- Reply Thread --}}
                        @if($expandedComplaint === $c->id)
                            <div class="border-t bg-gray-50 p-4 space-y-3">
                                @php $replies = $this->getReplies($c->id); @endphp
                                @forelse($replies as $r)
                                    <div class="flex gap-3">
                                        <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold flex-shrink-0">{{ strtoupper(substr($r->user_name ?? 'U', 0, 2)) }}</div>
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-semibold">{{ $r->user_name }}</span>
                                                <span class="text-xs text-gray-400">{{ fmtDateTime($r->created_at) }}</span>
                                            </div>
                                            <p class="text-sm text-gray-600 mt-1">{{ $r->text }}</p>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-center text-gray-400 text-sm py-2">No replies yet</p>
                                @endforelse

                                {{-- Inline Reply Form --}}
                                <div class="flex gap-2 pt-2">
                                    <input type="text" wire:model="replyText" placeholder="Type a reply..." class="form-input flex-1" wire:keydown.enter="sendReply({{ $c->id }})">
                                    <button wire:click="sendReply({{ $c->id }})" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane text-sm"></i></button>
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="bg-white rounded-2xl border border-gray-100 p-8 text-center">
                        <i class="fas fa-inbox text-4xl text-gray-200 mb-3"></i>
                        <p class="text-gray-400 text-sm">No complaints found</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-4">
                {{ $this->complaints->links() }}
            </div>

            {{-- Complaint Form Modal --}}
            @if($showForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showForm', false)" x-on:keydown.escape.window="$wire.set('showForm', false)">
                    <div class="modal-box w-full max-w-lg mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingId ? 'Edit' : 'New' }} Complaint</h3>
                            <button wire:click="$set('showForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div><label class="form-label">Title</label><input type="text" wire:model="formTitle" class="form-input" placeholder="Brief description of the issue"><span wire:error="formTitle" class="text-red-500 text-xs mt-1 block"></span></div>
                            <div><label class="form-label">Description</label><textarea wire:model="formDescription" class="form-input" rows="4" placeholder="Detailed description..."></textarea><span wire:error="formDescription" class="text-red-500 text-xs mt-1 block"></span></div>
                            <div class="grid grid-cols-2 gap-3">
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
                            <button wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">Save</span><span wire:loading wire:target="save" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...</span></button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        blade;
    }
};
