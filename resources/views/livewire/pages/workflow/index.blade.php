<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\Client;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\PackageService;
use App\Services\RbacService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $search = '';
    public string $clientFilter = '';
    public string $typeFilter = '';
    public string $priorityFilter = '';

    public bool $showForm = false;
    public string $formMode = 'create';
    public int $editingId = 0;

    public string $formTitle = '';
    public string $formDescription = '';
    public ?int $formClientId = null;
    public string $formType = 'post';
    public string $formPriority = 'medium';
    public ?int $formAssignee = null;
    public string $formStage = '';
    public ?string $formDeadline = null;
    public string $formTags = '';

    public bool $showStageManager = false;
    public array $stages = [];
    public string $newStageName = '';
    public string $newStageColor = '#4f46e5';
    public string $editingStageColor = '#4f46e5';
    public ?int $editingStageId = null;

    public array $presetColors = [
        '#4f46e5', '#7c3aed', '#db2777', '#dc2626', '#ea580c',
        '#ca8a04', '#16a34a', '#0891b2', '#2563eb', '#6b7280',
    ];

    public function mount(): void
    {
        $this->loadStages();
        if (!empty($this->stages) && !$this->formStage) {
            $this->formStage = $this->stages[0]['key'] ?? '';
        }
    }

    public function loadStages(): void
    {
        $this->stages = WorkflowStage::orderBy('order')->get()->toArray();
    }

    #[Computed]
    public function canMoveWorkflow(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        $rbac = app(RbacService::class);
        return $rbac->hasDataAccess($user->role, 'canMoveWorkflow');
    }

    #[Computed]
    public function canEditWorkflow(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        $rbac = app(RbacService::class);
        return $rbac->hasDataAccess($user->role, 'canEditWorkflow');
    }

    public function getStages()
    {
        return collect($this->stages);
    }

    public function getFilteredItems()
    {
        $query = Workflow::with(['client', 'stageInfo', 'assigneeUser']);

        $user = Auth::user();
        $rbac = app(RbacService::class);
        if ($user && !$rbac->hasDataAccess($user->role, 'seeAllWorkflow')) {
            $query->where('assignee', $user->id);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', "%{$this->search}%")
                  ->orWhere('tags', 'like', "%{$this->search}%");
            });
        }

        if ($this->clientFilter) {
            $query->where('client_id', $this->clientFilter);
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        if ($this->priorityFilter) {
            $query->where('priority', $this->priorityFilter);
        }

        return $query->orderBy('deadline', 'asc')->get();
    }

    public function getItemsForStage(string $stageKey)
    {
        return $this->getFilteredItems()->filter(fn($item) => $item->stage === $stageKey);
    }

    public function moveItem(int $itemId, string $newStage): void
    {
        $workflow = Workflow::find($itemId);
        if ($workflow) {
            $oldStage = $workflow->stage;
            $workflow->update(['stage' => $newStage]);

            // Plan §4.5 requires logging old → new stage transitions.
            app(ActivityLogger::class)->record(
                Auth::user(),
                "Moved workflow '{$workflow->title}' {$oldStage} → {$newStage}"
            );
            app(NotificationService::class)->sendNotification(
                text: "Workflow '{$workflow->title}' moved {$oldStage} → {$newStage}",
                type: 'info',
                link: route('workflow', absolute: false),
                forRole: 'all',
            );

            $this->dispatch('toast', message: 'Item moved successfully', type: 'success');
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $workflow = Workflow::find($id);
        if (!$workflow) return;

        $this->formMode = 'edit';
        $this->editingId = $id;
        $this->formTitle = $workflow->title;
        $this->formDescription = $workflow->notes ?? '';
        $this->formClientId = $workflow->client_id;
        $this->formType = $workflow->type;
        $this->formPriority = $workflow->priority;
        $this->formAssignee = $workflow->assignee;
        $this->formStage = $workflow->stage;
        $this->formDeadline = $workflow->deadline?->format('Y-m-d');
        $this->formTags = $workflow->tags ?? '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'formTitle'      => 'required|string|max:255',
            'formClientId'   => 'required|exists:clients,id',
            'formType'       => 'required|string|in:reel,post,story,video,carousel,blog',
            'formPriority'   => 'required|string|in:low,medium,high,urgent',
            'formStage'      => 'required|string',
            'formDeadline'   => 'nullable|date',
            'formAssignee'   => 'nullable|exists:users,id',
        ]);

        $data = [
            'title'       => $this->formTitle,
            'notes'       => $this->formDescription,
            'client_id'   => $this->formClientId,
            'type'        => $this->formType,
            'priority'    => $this->formPriority,
            'assignee'    => $this->formAssignee,
            'stage'       => $this->formStage,
            'deadline'    => $this->formDeadline,
            'tags'        => $this->formTags,
            'submitted_by'=> auth()->id(),
        ];

        if ($this->formMode === 'edit' && $this->editingId) {
            $priorAssignee = Workflow::find($this->editingId)?->assignee;
            Workflow::findOrFail($this->editingId)->update($data);
            $workflowId = $this->editingId;
            $verb = 'updated';
        } else {
            $priorAssignee = null;
            $workflowId = Workflow::create($data)->id;
            $verb = 'created';
            // Track package usage
            PackageService::recordWorkflow($this->formClientId);
        }

        app(ActivityLogger::class)->record(Auth::user(), "Workflow '{$this->formTitle}' {$verb}");

        // Notify assignee when set or changed.
        if ($this->formAssignee && $this->formAssignee != $priorAssignee) {
            $assigneeUser = User::find($this->formAssignee);
            if ($assigneeUser) {
                app(NotificationService::class)->sendNotification(
                    text: "You were assigned workflow item '{$this->formTitle}'",
                    type: 'info',
                    link: route('workflow', absolute: false),
                    forRole: $assigneeUser->role,
                );
            }
        }

        $this->dispatch('toast', message: "Workflow item {$verb}", type: 'success');
        $this->showForm = false;
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        Workflow::find($id)?->delete();
        app(ActivityLogger::class)->record(Auth::user(), "Deleted workflow #{$id}");
        $this->dispatch('toast', message: 'Workflow item deleted', type: 'success');
    }

    public function openStageManager(): void
    {
        $this->loadStages();
        $this->newStageName = '';
        $this->newStageColor = '#4f46e5';
        $this->showStageManager = true;
    }

    public function addStage(): void
    {
        $this->validate([
            'newStageName' => 'required|string|max:255',
        ]);

        $key = strtolower(trim(preg_replace('/[^A-Za-z0-9-]/', '-', $this->newStageName), '-'));

        WorkflowStage::create([
            'key'   => $key,
            'name'  => $this->newStageName,
            'color' => $this->newStageColor,
            'order' => WorkflowStage::max('order') + 1,
        ]);

        app(ActivityLogger::class)->record(Auth::user(), "Added workflow stage '{$this->newStageName}'");

        $this->newStageName = '';
        $this->newStageColor = '#4f46e5';
        $this->loadStages();
        $this->dispatch('toast', message: 'Stage added', type: 'success');
    }

    public function deleteStage(int $id): void
    {
        $stage = WorkflowStage::find($id);
        if ($stage) {
            $count = Workflow::where('stage', $stage->key)->count();
            if ($count > 0) {
                $this->dispatch('toast', message: 'Cannot delete stage with items. Move items first.', type: 'error');
                return;
            }
            $stage->delete();
            $this->loadStages();
            $this->dispatch('toast', message: 'Stage deleted', type: 'success');
        }
    }

    public function updateStageColor(int $id, string $color): void
    {
        WorkflowStage::where('id', $id)->update(['color' => $color]);
        $this->loadStages();
    }

    public function reorderStages(array $stageIds): void
    {
        foreach ($stageIds as $index => $stageId) {
            WorkflowStage::where('id', $stageId)->update(['order' => $index]);
        }
        $this->loadStages();
    }

    public function resetForm(): void
    {
        $this->editingId = 0;
        $this->formTitle = '';
        $this->formDescription = '';
        $this->formClientId = null;
        $this->formType = 'post';
        $this->formPriority = 'medium';
        $this->formAssignee = null;
        $this->formDeadline = null;
        $this->formTags = '';
        if (!empty($this->stages)) {
            $this->formStage = $this->stages[0]['key'] ?? '';
        } else {
            $this->formStage = '';
        }
    }

    public function getClientList()
    {
        return Client::orderBy('name')->get();
    }

    public function getUserList()
    {
        return User::orderBy('name')->get();
    }
}; ?>

<div>
    {{-- ========== HEADER ========== --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-900">Workflow</h1>
            <p class="text-sm text-gray-500 mt-1">Manage your content workflow pipeline</p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openStageManager" class="btn btn-secondary">
                <i class="fas fa-cog text-xs"></i> Manage Stages
            </button>
            <button wire:click="create" class="btn btn-primary">
                <i class="fas fa-plus text-xs"></i> Add Item
            </button>
        </div>
    </div>

    {{-- ========== FILTERS BAR ========== --}}
    <div class="mb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <div><label class="form-label">Search</label><div class="relative">
            <input type="text" wire:model.live.debounce.250ms="search" placeholder="Search workflows..." class="form-input pl-10" />
            <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
        </div></div>
        <div><label class="form-label">Client</label><select wire:model.live="clientFilter" class="form-select">
            <option value="">All Clients</option>
            @foreach($this->getClientList() as $client)
                <option value="{{ $client->id }}">{{ $client->name }}</option>
            @endforeach
        </select></div>
        <div><label class="form-label">Type</label><select wire:model.live="typeFilter" class="form-select">
            <option value="">All Types</option>
            <option value="reel">Reel</option>
            <option value="post">Post</option>
            <option value="story">Story</option>
            <option value="video">Video</option>
            <option value="carousel">Carousel</option>
            <option value="blog">Blog</option>
        </select></div>
        <div><label class="form-label">Priority</label><select wire:model.live="priorityFilter" class="form-select">
            <option value="">All Priorities</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
        </select></div>
    </div>

    {{-- ========== KANBAN BOARD ========== --}}
    @php $allItems = $this->getFilteredItems(); @endphp

    <div
        class="flex gap-4 overflow-x-auto pb-6 kanban-board"
        x-data="{
            draggedId: null,
            justDragged: false,
            dragStart(e, id) {
                this.draggedId = id;
                this.justDragged = true;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', id);
                requestAnimationFrame(() => {
                    e.target.closest('.kanban-card')?.classList.add('opacity-50');
                });
            },
            dragEnd(e) {
                e.target.closest('.kanban-card')?.classList.remove('opacity-50');
                this.draggedId = null;
                setTimeout(() => { this.justDragged = false; }, 100);
            },
            dragOver(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                e.currentTarget.classList.add('drag-over');
            },
            dragLeave(e) {
                if (!e.currentTarget.contains(e.relatedTarget)) {
                    e.currentTarget.classList.remove('drag-over');
                }
            },
            drop(e, stageKey) {
                e.preventDefault();
                e.currentTarget.classList.remove('drag-over');
                if (this.draggedId) {
                    $wire.moveItem(parseInt(this.draggedId), stageKey);
                    this.draggedId = null;
                }
            },
            openCard(id) {
                if (!this.justDragged && $wire.canEditWorkflow) {
                    $wire.edit(id);
                }
            }
        }"
    >
        @forelse($this->getStages() as $stage)
            @php
                $stageItems = $allItems->filter(fn($item) => $item->stage === $stage['key']);
            @endphp
            <div
                class="kanban-col flex-shrink-0"
                style="min-width: 280px; width: 280px;"
            >
                {{-- Column Header --}}
                <div class="flex items-center gap-2 mb-3 px-1">
                    <div class="h-2.5 w-2.5 rounded-full flex-shrink-0" style="background-color: {{ $stage['color'] }}"></div>
                    <h3 class="text-sm font-bold text-gray-800 truncate">{{ $stage['name'] }}</h3>
                    <span class="ml-auto inline-flex items-center justify-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500">
                        {{ $stageItems->count() }}
                    </span>
                </div>

                {{-- Drop Zone --}}
                <div
                    class="cards-area rounded-xl bg-gray-50/80 border-2 border-dashed border-transparent p-2 min-h-[200px] space-y-2 transition-all duration-200"
                    x-on:dragover="dragOver($event)"
                    x-on:dragleave="dragLeave($event)"
                    x-on:drop="drop($event, '{{ $stage['key'] }}')"
                >
                    @forelse($stageItems as $item)
                        @php
                            $isOverdue = $item->deadline && $item->deadline->isPast() && $item->stage !== 'published';
                        @endphp
                        <div
                            class="kanban-card {{ $isOverdue ? 'overdue' : '' }} bg-white rounded-xl border border-gray-100 p-3 {{ $this->canMoveWorkflow ? 'cursor-grab active:cursor-grabbing' : '' }} hover:shadow-md hover:border-gray-200 transition-all duration-150"
                            data-id="{{ $item->id }}"
                            @if($this->canMoveWorkflow) draggable="true" @endif
                            @if($this->canMoveWorkflow) x-on:dragstart="dragStart($event, {{ $item->id }})" @endif
                            @if($this->canMoveWorkflow) x-on:dragend="dragEnd($event)" @endif
                            x-on:click="openCard({{ $item->id }})"
                        >
                            {{-- Type + Priority Badges --}}
                            <div class="flex items-center gap-1.5 mb-2">
                                @php
                                    $typeColors = [
                                        'reel'     => 'bg-pink-100 text-pink-700',
                                        'post'     => 'bg-blue-100 text-blue-700',
                                        'story'    => 'bg-purple-100 text-purple-700',
                                        'video'    => 'bg-red-100 text-red-700',
                                        'carousel' => 'bg-amber-100 text-amber-700',
                                        'blog'     => 'bg-green-100 text-green-700',
                                    ];
                                    $priorityColors = [
                                        'low'    => 'bg-gray-100 text-gray-600',
                                        'medium' => 'bg-blue-100 text-blue-600',
                                        'high'   => 'bg-orange-100 text-orange-600',
                                        'urgent' => 'bg-red-100 text-red-600',
                                    ];
                                @endphp
                                <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold {{ $typeColors[$item->type] ?? 'bg-gray-100 text-gray-600' }}">
                                    {{ ucfirst($item->type) }}
                                </span>
                                <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold {{ $priorityColors[$item->priority] ?? 'bg-gray-100 text-gray-600' }}">
                                    {{ ucfirst($item->priority) }}
                                </span>
                            </div>

                            {{-- Title --}}
                            <h4 class="text-sm font-semibold text-gray-900 mb-1.5 leading-snug line-clamp-2">{{ $item->title }}</h4>

                            {{-- Client --}}
                            <p class="text-xs text-gray-500 mb-2 truncate">
                                <i class="fas fa-building mr-1 text-gray-400"></i>
                                {{ $item->client->name ?? '—' }}
                            </p>

                            {{-- Bottom: Assignee + Deadline --}}
                            <div class="flex items-center justify-between mt-2">
                                @if($item->assigneeUser)
                                    <div class="flex items-center gap-1.5">
                                        <div class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-[9px] font-bold text-[var(--brand)]">
                                            {{ $item->assigneeUser->initials }}
                                        </div>
                                        <span class="text-[11px] text-gray-500 truncate max-w-[80px]">{{ $item->assigneeUser->name }}</span>
                                    </div>
                                @else
                                    <span class="text-[11px] text-gray-400">Unassigned</span>
                                @endif

                                @if($item->deadline)
                                    <span class="inline-flex items-center gap-1 text-[11px] {{ $isOverdue ? 'text-red-600 font-semibold' : 'text-gray-500' }}">
                                        <i class="fas fa-calendar-alt text-[10px] {{ $isOverdue ? 'text-red-500' : 'text-gray-400' }}"></i>
                                        {{ $item->deadline->format('M d') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center">
                            <i class="fas fa-inbox text-xl text-gray-200 mb-1"></i>
                            <p class="text-[11px] text-gray-400">No items</p>
                        </div>
                    @endforelse
                </div>
            </div>
        @empty
            <div class="flex-1 bg-white rounded-2xl border border-gray-100 p-12 text-center">
                <i class="fas fa-columns text-4xl text-gray-200 mb-3"></i>
                <p class="text-gray-500 font-medium text-sm">No stages configured</p>
                <p class="text-gray-400 text-xs mt-1">Click "Manage Stages" to add workflow columns</p>
            </div>
        @endforelse
    </div>

    {{-- ========== WORKFLOW FORM MODAL ========== --}}
    @if($showForm)
        <div
            class="modal-overlay"
            x-data
            x-on:keydown.escape.window="$wire.set('showForm', false)"
        >
            <div
                class="modal-box max-w-2xl max-h-[90vh]"
                x-on:click.stop
            >
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900">
                        <i class="fas fa-{{ $formMode === 'edit' ? 'pen' : 'plus' }} text-[var(--brand)] mr-2"></i>
                        {{ $formMode === 'edit' ? 'Edit Workflow Item' : 'Add Workflow Item' }}
                    </h3>
                    <button
                        wire:click="$set('showForm', false)"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <div class="modal-body overflow-y-auto max-h-[calc(90vh-80px)]">
                    <form wire:submit="save">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="form-label">Title <span class="text-red-500">*</span></label>
                                <input type="text" wire:model="formTitle" class="form-input" placeholder="Enter item title" />
                                @error('formTitle') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div class="md:col-span-2">
                                <label class="form-label">Description</label>
                                <textarea wire:model="formDescription" class="form-textarea" rows="3" placeholder="Brief description or notes..."></textarea>
                            </div>

                            <div>
                                <label class="form-label">Client <span class="text-red-500">*</span></label>
                                <select wire:model="formClientId" class="form-select">
                                    <option value="">Select Client</option>
                                    @foreach($this->getClientList() as $client)
                                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                                    @endforeach
                                </select>
                                @error('formClientId') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="form-label">Type <span class="text-red-500">*</span></label>
                                <select wire:model="formType" class="form-select">
                                    <option value="reel">Reel</option>
                                    <option value="post">Post</option>
                                    <option value="story">Story</option>
                                    <option value="video">Video</option>
                                    <option value="carousel">Carousel</option>
                                    <option value="blog">Blog</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Priority <span class="text-red-500">*</span></label>
                                <select wire:model="formPriority" class="form-select">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Assignee</label>
                                <select wire:model="formAssignee" class="form-select">
                                    <option value="">Unassigned</option>
                                    @foreach($this->getUserList() as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Stage <span class="text-red-500">*</span></label>
                                <select wire:model="formStage" class="form-select">
                                    @foreach($this->getStages() as $stage)
                                        <option value="{{ $stage['key'] }}">{{ $stage['name'] }}</option>
                                    @endforeach
                                </select>
                                @error('formStage') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="form-label">Deadline</label>
                                <input type="date" wire:model="formDeadline" class="form-input" />
                            </div>

                            <div class="md:col-span-2">
                                <label class="form-label">Tags</label>
                                <input
                                    type="text"
                                    wire:model="formTags"
                                    class="form-input"
                                    placeholder="Comma-separated tags (e.g. urgent, design, revision)"
                                />
                                <p class="text-[11px] text-gray-400 mt-1">Separate multiple tags with commas</p>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3 border-t border-gray-100 pt-5">
                            <button
                                type="button"
                                wire:click="$set('showForm', false)"
                                class="btn btn-secondary"
                            >
                                Cancel
                            </button>
                            @if($formMode === 'edit')
                                <button
                                    type="button"
                                    wire:click="delete({{ $editingId }})"
                                    wire:confirm="Are you sure you want to delete this item?"
                                    class="btn bg-red-50 text-red-600 border border-red-200 hover:bg-red-100"
                                >
                                    <i class="fas fa-trash text-xs"></i> Delete
                                </button>
                            @endif
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save text-xs"></i>
                                {{ $formMode === 'edit' ? 'Update Item' : 'Create Item' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ========== STAGE MANAGER MODAL ========== --}}
    @if($showStageManager)
        <div
            class="modal-overlay"
            x-data="{
                sortable: null,
                init() {
                    this.$nextTick(() => {
                        const el = this.$refs.stageList;
                        if (el && typeof Sortable !== 'undefined') {
                            this.sortable = new Sortable(el, {
                                animation: 150,
                                handle: '.drag-handle',
                                ghostClass: 'opacity-40',
                                onEnd: (evt) => {
                                    const ids = Array.from(el.children).map(child => child.dataset.stageId);
                                    $wire.reorderStages(ids.map(id => parseInt(id)));
                                }
                            });
                        }
                    });
                }
            }"
            x-on:keydown.escape.window="$wire.set('showStageManager', false)"
        >
            <div
                class="modal-box max-w-lg max-h-[85vh]"
                x-on:click.stop
            >
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900">
                        <i class="fas fa-cog text-[var(--brand)] mr-2"></i>
                        Manage Workflow Stages
                    </h3>
                    <button
                        wire:click="$set('showStageManager', false)"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <div class="modal-body overflow-y-auto max-h-[calc(85vh-80px)]">
                    {{-- Existing Stages --}}
                    <div x-ref="stageList" class="space-y-2 mb-5">
                        @forelse($this->getStages() as $stage)
                            <div
                                class="flex items-center gap-3 rounded-xl border border-gray-100 bg-white p-3 group"
                                data-stage-id="{{ $stage['id'] }}"
                            >
                                <div class="drag-handle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500">
                                    <i class="fas fa-grip-vertical text-sm"></i>
                                </div>

                                <div class="relative" x-data="{ open: false }">
                                    <button
                                        @click="open = !open"
                                        class="h-7 w-7 rounded-lg border-2 border-white shadow-sm flex-shrink-0 transition-colors hover:opacity-80"
                                        style="background-color: {{ $stage['color'] }}"
                                    ></button>
                                    <div
                                        x-show="open"
                                        @click.away="open = false"
                                        x-transition
                                        class="absolute top-9 left-0 z-20 bg-white rounded-xl border border-gray-100 shadow-lg p-3 grid grid-cols-5 gap-2 w-[180px]"
                                    >
                                        @foreach($this->presetColors as $color)
                                            <button
                                                type="button"
                                                class="h-7 w-7 rounded-lg border-2 transition-all hover:scale-110 {{ $stage['color'] === $color ? 'border-gray-900 ring-2 ring-gray-200' : 'border-white' }}"
                                                style="background-color: {{ $color }}"
                                                wire:click="updateStageColor({{ $stage['id'] }}, '{{ $color }}')"
                                            ></button>
                                        @endforeach
                                    </div>
                                </div>

                                <span class="flex-1 text-sm font-semibold text-gray-800">{{ $stage['name'] }}</span>

                                <span class="text-[11px] text-gray-400 mr-1">
                                    {{ Workflow::where('stage', $stage['key'])->count() }} items
                                </span>

                                <button
                                    wire:click="deleteStage({{ $stage['id'] }})"
                                    wire:confirm="Delete this stage? Items in this stage will not be lost."
                                    class="flex h-7 w-7 items-center justify-center rounded-lg text-gray-300 hover:text-red-500 hover:bg-red-50 transition-colors opacity-0 group-hover:opacity-100"
                                >
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                            </div>
                        @empty
                            <div class="py-8 text-center">
                                <i class="fas fa-columns text-2xl text-gray-200 mb-2"></i>
                                <p class="text-gray-400 text-xs">No stages yet. Add one below.</p>
                            </div>
                        @endforelse
                    </div>

                    {{-- Add New Stage --}}
                    <div class="border-t border-gray-100 pt-4">
                        <p class="text-xs font-semibold text-gray-500 mb-3">Add New Stage</p>
                        <div class="flex items-center gap-3">
                            <div class="relative" x-data="{ open: false }">
                                <button
                                    @click="open = !open"
                                    class="h-9 w-9 rounded-lg border-2 border-white shadow-sm flex-shrink-0 transition-colors hover:opacity-80"
                                    style="background-color: {{ $newStageColor }}"
                                ></button>
                                <div
                                    x-show="open"
                                    @click.away="open = false"
                                    x-transition
                                    class="absolute bottom-12 left-0 z-20 bg-white rounded-xl border border-gray-100 shadow-lg p-3 grid grid-cols-5 gap-2 w-[180px]"
                                >
                                    @foreach($this->presetColors as $color)
                                        <button
                                            type="button"
                                            class="h-7 w-7 rounded-lg border-2 transition-all hover:scale-110 {{ $newStageColor === $color ? 'border-gray-900 ring-2 ring-gray-200' : 'border-white' }}"
                                            style="background-color: {{ $color }}"
                                            wire:click="$set('newStageColor', '{{ $color }}')"
                                        ></button>
                                    @endforeach
                                </div>
                            </div>

                            <input
                                type="text"
                                wire:model="newStageName"
                                class="form-input flex-1"
                                placeholder="Stage name"
                                x-on:keydown.enter.prevent="$wire.addStage()"
                            />

                            <button
                                wire:click="addStage"
                                class="btn btn-primary"
                            >
                                <i class="fas fa-plus text-xs"></i> Add
                            </button>
                        </div>
                        @error('newStageName') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
