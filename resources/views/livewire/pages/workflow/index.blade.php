<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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
    public ?int $formContentId = null;
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

    public bool $showDetail = false;
    public int $detailId = 0;

    public array $presetColors = [
        '#4f46e5', '#7c3aed', '#a855f7', '#db2777', '#ec4899',
        '#f43f5e', '#dc2626', '#ea580c', '#f97316', '#eab308',
        '#ca8a04', '#84cc16', '#16a34a', '#10b981', '#14b8a6',
        '#06b6d4', '#0891b2', '#2563eb', '#3b82f6', '#6b7280',
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

    public function getAvailableContent(): \Illuminate\Support\Collection
    {
        if (!$this->formClientId) return collect();
        return DB::table('contents')
            ->where('client_id', $this->formClientId)
            ->orderBy('date', 'desc')
            ->get();
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
        $this->formContentId = $workflow->content_id;
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
            'formClientId'   => 'nullable|exists:clients,id',
            'formType'       => 'required|string|in:reel,post,story,video,carousel,blog',
            'formPriority'   => 'required|string|in:low,medium,high,urgent',
            'formStage'      => 'required|string',
            'formDeadline'   => 'nullable|date|after_or_equal:today',
            'formAssignee'   => 'nullable|exists:users,id',
        ], [
            'formDeadline.after_or_equal' => 'Deadline must be today or a future date',
        ]);

        $data = [
            'title'       => $this->formTitle,
            'notes'       => $this->formDescription,
            'client_id'   => $this->formClientId,
            'content_id'  => $this->formContentId ?: null,
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
        $this->dispatch('workflowUpdated');
    }

    public function delete(int $id): void
    {
        Workflow::find($id)?->delete();
        app(ActivityLogger::class)->record(Auth::user(), "Deleted workflow #{$id}");
        $this->dispatch('workflowUpdated');
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

        $name = trim($this->newStageName);
        if (WorkflowStage::where('name', $name)->exists()) {
            $this->dispatch('toast', message: 'A stage with that name already exists', type: 'error');
            return;
        }

        $key = strtolower(trim(preg_replace('/[^A-Za-z0-9-]/', '-', $name), '-'));
        if ($key === '') $key = 'stage-' . (WorkflowStage::max('id') + 1);
        if (WorkflowStage::where('key', $key)->exists()) {
            $key .= '-' . (WorkflowStage::max('id') + 1);
        }

        WorkflowStage::create([
            'key'   => $key,
            'name'  => $name,
            'color' => $this->newStageColor,
            'order' => WorkflowStage::max('order') + 1,
        ]);

        app(ActivityLogger::class)->record(Auth::user(), "Added workflow stage '{$name}'");

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
        // Accept hex colors only (either #RGB or #RRGGBB) to avoid style-injection.
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) return;
        WorkflowStage::where('id', $id)->update(['color' => $color]);
        $this->loadStages();
    }

    public function renameStage(int $id, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            $this->dispatch('toast', message: 'Stage name cannot be empty', type: 'error');
            $this->loadStages();
            return;
        }
        if (mb_strlen($name) > 255) {
            $this->dispatch('toast', message: 'Stage name is too long', type: 'error');
            $this->loadStages();
            return;
        }
        $duplicate = WorkflowStage::where('name', $name)->where('id', '!=', $id)->exists();
        if ($duplicate) {
            $this->dispatch('toast', message: 'A stage with that name already exists', type: 'error');
            $this->loadStages();
            return;
        }
        $stage = WorkflowStage::find($id);
        if (!$stage) return;
        $old = $stage->name;
        if ($old === $name) return;
        $stage->update(['name' => $name]);
        app(ActivityLogger::class)->record(Auth::user(), "Renamed workflow stage '{$old}' → '{$name}'");
        $this->loadStages();
        $this->dispatch('toast', message: 'Stage renamed', type: 'success');
    }

    public function viewItem(int $id): void
    {
        if (!Workflow::whereKey($id)->exists()) return;
        $this->detailId = $id;
        $this->showDetail = true;
    }

    public function editFromDetail(): void
    {
        if (!$this->detailId) return;
        if (!$this->canEditWorkflow) return;
        $id = $this->detailId;
        $this->showDetail = false;
        $this->edit($id);
    }

    public function getDetailItem()
    {
        if (!$this->detailId) return null;
        return Workflow::with(['client', 'stageInfo', 'assigneeUser'])->find($this->detailId);
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
        $this->formContentId = null;
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

    #[On('confirm-resolved')]
    public function onConfirmResolved(string $action, array $params = []): void
    {
        if ($action !== '' && method_exists($this, $action)) {
            $this->{$action}(...$params);
        }
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
            <button wire:click="create" class="btn btn-primary"><i class="fas fa-plus text-xs"></i> Add Item</button>
        </div>
    </div>

    {{-- ========== FILTERS BAR ========== --}}
    <div class="mb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <div>
            <label class="form-label">Search</label>
            <div class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.250ms="search"
                    placeholder="Search workflows..."
                    class="form-input pl-10 focus:ring-0"
                />
                <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            </div>
        </div>
        <div>
            <label class="form-label">Client</label
            ><select wire:model.live="clientFilter" class="form-select">
                <option value="">All Clients</option>
                @foreach ($this->getClientList() as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Type</label
            ><select wire:model.live="typeFilter" class="form-select">
                <option value="">All Types</option>
                <option value="reel">Reel</option>
                <option value="post">Post</option>
                <option value="story">Story</option>
                <option value="video">Video</option>
                <option value="carousel">Carousel</option>
                <option value="blog">Blog</option>
            </select>
        </div>
        <div>
            <label class="form-label">Priority</label
            ><select wire:model.live="priorityFilter" class="form-select">
                <option value="">All Priorities</option>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
            </select>
        </div>
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
                setTimeout(() => {
                    this.justDragged = false;
                }, 100);
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
                if (!this.justDragged) {
                    $wire.viewItem(id);
                }
            }
        }"
    >
        @forelse ($this->getStages() as $stage)
            @php
                $stageItems = $allItems->filter(fn($item) => $item->stage === $stage['key']);
            @endphp
            <div class="kanban-col flex-shrink-0" style="min-width: 280px; width: 280px">
                {{-- Column Header --}}
                <div class="flex items-center gap-2 mb-3 px-1">
                    <div
                        class="h-2.5 w-2.5 rounded-full flex-shrink-0"
                        style="background-color: {{ $stage['color'] }}"
                    ></div>
                    <h3 class="text-sm font-bold text-gray-800 truncate">{{ $stage['name'] }}</h3>
                    <span
                        class="ml-auto inline-flex items-center justify-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500"
                    >
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
                    @forelse ($stageItems as $item)
                        @php
                            $isOverdue = $item->deadline && $item->deadline->isPast() && $item->stage !== 'published';
                        @endphp
                        <div
                            class="kanban-card {{ $isOverdue ? 'overdue' : '' }} bg-white rounded-xl border border-gray-100 p-3 {{ $this->canMoveWorkflow ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer' }} hover:shadow-md hover:border-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]/40 focus-visible:border-[var(--brand)] transition-all duration-150"
                            data-id="{{ $item->id }}"
                            role="button"
                            tabindex="0"
                            aria-label="View workflow item: {{ $item->title }}"
                            @if ($this->canMoveWorkflow) draggable="true" @endif
                            @if ($this->canMoveWorkflow) x-on:dragstart="dragStart($event, {{ $item->id }})" @endif
                            @if ($this->canMoveWorkflow) x-on:dragend="dragEnd($event)" @endif
                            x-on:click="openCard({{ $item->id }})"
                            x-on:keydown.enter.prevent="openCard({{ $item->id }})"
                            x-on:keydown.space.prevent="openCard({{ $item->id }})"
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
                                <span
                                    class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold {{ $typeColors[$item->type] ?? 'bg-gray-100 text-gray-600' }}"
                                >
                                    {{ ucfirst($item->type) }}
                                </span>
                                <span
                                    class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold {{ $priorityColors[$item->priority] ?? 'bg-gray-100 text-gray-600' }}"
                                >
                                    {{ ucfirst($item->priority) }}
                                </span>
                            </div>

                            {{-- Title --}}
                            <h4 class="text-sm font-semibold text-gray-900 mb-1.5 leading-snug line-clamp-2">
                                {{ $item->title }}
                            </h4>

                            {{-- Client --}}
                            <p class="text-xs text-gray-500 mb-2 truncate">
                                <i class="fas fa-building mr-1 text-gray-400"></i>
                                {{ $item->client->name ?? '—' }}
                            </p>

                            {{-- Bottom: Assignee + Deadline --}}
                            <div class="flex items-center justify-between mt-2">
                                @if ($item->assigneeUser)
                                    <div class="flex items-center gap-1.5">
                                        <div
                                            class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-[9px] font-bold text-[var(--brand)]"
                                        >
                                            {{ $item->assigneeUser->initials }}
                                        </div>
                                        <span
                                            class="text-[11px] text-gray-500 truncate max-w-[80px]"
                                            >{{ $item->assigneeUser->name }}</span
                                        >
                                    </div>
                                @else
                                    <span class="text-[11px] text-gray-400">Unassigned</span>
                                @endif

                                @if ($item->deadline)
                                    <span
                                        class="inline-flex items-center gap-1 text-[11px] {{ $isOverdue ? 'text-red-600 font-semibold' : 'text-gray-500' }}"
                                    >
                                        <i
                                            class="fas fa-calendar-alt text-[10px] {{ $isOverdue ? 'text-red-500' : 'text-gray-400' }}"
                                        ></i>
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

    {{-- ========== WORKFLOW DETAIL MODAL (read-only) ========== --}}
    @if ($showDetail)
        @php $detail = $this->getDetailItem(); @endphp
        @if ($detail)
            @php
                $priorityBadge = match($detail->priority) {
                    'urgent' => 'bg-red-100 text-red-700',
                    'high'   => 'bg-orange-100 text-orange-700',
                    'medium' => 'bg-blue-100 text-blue-700',
                    default  => 'bg-gray-100 text-gray-600',
                };
                $typeBadge = [
                    'reel'     => 'bg-pink-100 text-pink-700',
                    'post'     => 'bg-blue-100 text-blue-700',
                    'story'    => 'bg-purple-100 text-purple-700',
                    'video'    => 'bg-red-100 text-red-700',
                    'carousel' => 'bg-amber-100 text-amber-700',
                    'blog'     => 'bg-green-100 text-green-700',
                ][$detail->type] ?? 'bg-gray-100 text-gray-600';
                $isOverdueDetail = $detail->deadline && $detail->deadline->isPast() && $detail->stage !== 'published';
            @endphp
            <div class="modal-overlay" x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">
                <div
                    class="modal-box max-w-2xl max-h-[90vh]"
                    x-on:click.stop
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="workflow-detail-title"
                >
                    <div class="modal-header">
                        <h3 id="workflow-detail-title" class="text-base font-bold text-gray-900">
                            <i class="fas fa-eye text-[var(--brand)] mr-2"></i>
                            Workflow Item
                        </h3>
                        <button
                            wire:click="$set('showDetail', false)"
                            aria-label="Close details"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]/40 transition-colors"
                        >
                            <i class="fas fa-times text-sm"></i>
                        </button>
                    </div>

                    <div class="modal-body overflow-y-auto max-h-[calc(90vh-160px)]">
                        {{-- Title + badges --}}
                        <div class="mb-5">
                            <div class="flex flex-wrap items-center gap-1.5 mb-2">
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $typeBadge }}"
                                >
                                    {{ ucfirst($detail->type) }}
                                </span>
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $priorityBadge }}"
                                >
                                    {{ ucfirst($detail->priority) }} priority
                                </span>
                                <span
                                    class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-gray-50 border border-gray-100 px-2.5 py-1 text-[11px] font-semibold text-gray-700"
                                >
                                    <span
                                        class="h-2 w-2 rounded-full"
                                        style="background-color: {{ $detail->stageInfo->color ?? '#6b7280' }}"
                                    ></span>
                                    {{ $detail->stageInfo->name ?? ucfirst($detail->stage) }}
                                </span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900 leading-snug">{{ $detail->title }}</h2>
                        </div>

                        {{-- Description --}}
                        @if ($detail->notes)
                            <div class="mb-5">
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-500 mb-1.5">Description</p>
                                <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $detail->notes }}</p>
                            </div>
                        @endif

                        {{-- Metadata grid --}}
                        <div class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm border-t border-gray-100 pt-4">
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-500 mb-1">Client</p>
                                <p class="text-gray-800"><i class="fas fa-building text-gray-400 mr-1.5"></i>{{ $detail->client->name ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-500 mb-1">Assignee</p>
                                @if ($detail->assigneeUser)
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="flex h-6 w-6 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-[10px] font-bold text-[var(--brand)]"
                                        >
                                            {{ $detail->assigneeUser->initials }}
                                        </div>
                                        <span class="text-gray-800">{{ $detail->assigneeUser->name }}</span>
                                    </div>
                                @else
                                    <p class="text-gray-400 italic">Unassigned</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-500 mb-1">Deadline</p>
                                @if ($detail->deadline)
                                    <p class="{{ $isOverdueDetail ? 'text-red-600 font-semibold' : 'text-gray-800' }}">
                                        <i
                                            class="fas fa-calendar-alt {{ $isOverdueDetail ? 'text-red-500' : 'text-gray-400' }} mr-1.5"
                                        ></i>
                                        {{ $detail->deadline->format('M d, Y') }}
                                        @if ($isOverdueDetail)
                                            <span
                                                class="ml-1 inline-flex items-center rounded-md bg-red-100 text-red-700 px-1.5 py-0.5 text-[10px] font-semibold"
                                                >Overdue</span
                                            >
                                        @endif
                                    </p>
                                @else
                                    <p class="text-gray-400 italic">No deadline</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-500 mb-1">Created</p>
                                <p class="text-gray-800">
                                    <i class="fas fa-clock text-gray-400 mr-1.5"></i>
                                    {{ $detail->created_at?->format('M d, Y') ?? '—' }}
                                </p>
                            </div>
                        </div>

                        {{-- Tags --}}
                        @if ($detail->tags)
                            <div class="mt-4 border-t border-gray-100 pt-4">
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-500 mb-2">Tags</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (explode(',', $detail->tags) as $tag)
                                        @php $tag = trim($tag); @endphp
                                        @if ($tag !== '')
                                            <span
                                                class="inline-flex items-center rounded-md bg-gray-100 text-gray-700 px-2 py-0.5 text-xs"
                                            >
                                                <i class="fas fa-hashtag text-gray-400 text-[9px] mr-1"></i>{{ $tag }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="border-t border-gray-100 px-6 py-4 flex items-center justify-end gap-3">
                        <button type="button" wire:click="$set('showDetail', false)" class="btn btn-secondary">
                            Close
                        </button>
                        @if ($this->canEditWorkflow)
                            <button type="button" wire:click="editFromDetail" class="btn btn-primary">
                                <i class="fas fa-pen text-xs"></i> Edit
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ========== WORKFLOW FORM MODAL ========== --}}
    @if ($showForm)
        <div class="modal-overlay" x-data x-on:keydown.escape.window="$wire.set('showForm', false)">
            <div class="modal-box max-w-2xl max-h-[90vh]" x-on:click.stop>
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
                                <input
                                    type="text"
                                    wire:model="formTitle"
                                    class="form-input"
                                    placeholder="Enter item title"
                                />
                                @error ('formTitle')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                                <span wire:error="formTitle" class="text-red-500 text-xs mt-1 block"></span>
                            </div>

                            <div class="md:col-span-2">
                                <label class="form-label">Description</label>
                                <textarea
                                    wire:model="formDescription"
                                    class="form-textarea"
                                    rows="3"
                                    placeholder="Brief description or notes..."
                                ></textarea>
                            </div>

                            <div>
                                <label class="form-label">Client <span class="text-red-500">*</span></label>
                                <select wire:model="formClientId" class="form-select">
                                    <option value="">Internal / Own Company</option>
                                    @foreach ($this->getClientList() as $client)
                                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                                    @endforeach
                                </select>
                                @error ('formClientId')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            @if ($formClientId)
                                <div>
                                    <label class="form-label"
                                        >Link to Content <span class="text-gray-400 text-xs">(optional)</span></label
                                    >
                                    <select wire:model="formContentId" class="form-select">
                                        <option value="">No linked content</option>
                                        @foreach ($this->getAvailableContent() as $content)
                                            <option value="{{ $content->id }}">
                                                {{ $content->title }} — {{ \Carbon\Carbon::parse($content->date)->format('M j') }} ({{ ucfirst($content->platform) }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

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
                                    @foreach ($this->getUserList() as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Stage <span class="text-red-500">*</span></label>
                                <select wire:model="formStage" class="form-select">
                                    @foreach ($this->getStages() as $stage)
                                        <option value="{{ $stage['key'] }}">{{ $stage['name'] }}</option>
                                    @endforeach
                                </select>
                                @error ('formStage')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            <div>
                                <label class="form-label">Deadline</label>
                                <input
                                    type="date"
                                    wire:model="formDeadline"
                                    class="form-input"
                                    min="{{ now()->format('Y-m-d') }}"
                                />
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
                            <button type="button" wire:click="$set('showForm', false)" class="btn btn-secondary">
                                Cancel
                            </button>
                            @if ($formMode === 'edit')
                                <button
                                    type="button"
                                    wire:click="$dispatch('open-confirm', { title: 'Delete Workflow Item?', message: 'This item and its activity will be permanently removed.', type: 'danger', action: 'delete', params: [{{ $editingId }}] })"
                                    class="btn bg-red-50 text-red-600 border border-red-200 hover:bg-red-100"
                                    aria-label="Delete workflow item"
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
    @if ($showStageManager)
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
                                    const ids = Array.from(el.children).map((child) => child.dataset.stageId);
                                    $wire.reorderStages(ids.map((id) => parseInt(id)));
                                }
                            });
                        }
                    });
                }
            }"
            x-on:keydown.escape.window="$wire.set('showStageManager', false)"
        >
            <div class="modal-box max-w-lg max-h-[85vh]" x-on:click.stop>
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
                        @forelse ($this->getStages() as $stage)
                            <div
                                class="flex items-center gap-3 rounded-xl border border-gray-100 bg-white p-3 group"
                                data-stage-id="{{ $stage['id'] }}"
                            >
                                <div
                                    class="drag-handle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500"
                                >
                                    <i class="fas fa-grip-vertical text-sm"></i>
                                </div>

                                <div class="relative" x-data="{ open: false }">
                                    <button
                                        type="button"
                                        @click="open = !open"
                                        aria-label="Change color"
                                        class="h-7 w-7 rounded-lg border-2 border-white shadow-sm flex-shrink-0 transition-transform hover:opacity-90 hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-300"
                                        style="background-color: {{ $stage['color'] }}"
                                    ></button>
                                    <div
                                        x-show="open"
                                        x-cloak
                                        @click.away="open = false"
                                        x-transition
                                        class="absolute top-9 left-0 z-30 bg-white rounded-xl border border-gray-100 shadow-lg p-3 w-[240px]"
                                    >
                                        <p class="text-[10px] uppercase tracking-wide font-semibold text-gray-400 mb-2">Preset</p>
                                        <div class="grid grid-cols-5 gap-2">
                                            @foreach ($this->presetColors as $color)
                                                <button
                                                    type="button"
                                                    class="h-7 w-7 rounded-lg border-2 transition-all hover:scale-110 {{ strtolower($stage['color']) === strtolower($color) ? 'border-gray-900 ring-2 ring-gray-200' : 'border-white' }}"
                                                    style="background-color: {{ $color }}"
                                                    aria-label="Set color {{ $color }}"
                                                    wire:click="updateStageColor({{ $stage['id'] }}, '{{ $color }}')"
                                                ></button>
                                            @endforeach
                                        </div>
                                        <div class="mt-3 pt-3 border-t border-gray-100">
                                            <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                                                <span
                                                    class="text-[10px] uppercase tracking-wide font-semibold text-gray-400"
                                                    >Custom</span
                                                >
                                                <input
                                                    type="color"
                                                    value="{{ $stage['color'] }}"
                                                    x-on:change="$wire.updateStageColor({{ $stage['id'] }}, $event.target.value)"
                                                    class="h-7 w-10 rounded cursor-pointer border border-gray-200"
                                                    aria-label="Pick custom color"
                                                />
                                                <span
                                                    class="text-gray-500 font-mono text-[11px]"
                                                    >{{ strtoupper($stage['color']) }}</span
                                                >
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <input
                                    type="text"
                                    value="{{ $stage['name'] }}"
                                    x-data="{ original: @js($stage['name']), saving: false }"
                                    x-on:blur="
                                        const v = $event.target.value.trim();
                                        if (v !== '' && v !== original && !saving) {
                                            saving = true;
                                            $wire.renameStage({{ $stage['id'] }}, v).then(() => { saving = false; });
                                            original = v;
                                        } else if (v === '') {
                                            $event.target.value = original;
                                        }
                                    "
                                    x-on:keydown.enter.prevent="$event.target.blur()"
                                    x-on:keydown.escape.prevent="
                                        $event.target.value = original;
                                        $event.target.blur();
                                    "
                                    maxlength="255"
                                    aria-label="Stage name (click to edit)"
                                    class="flex-1 min-w-0 text-sm font-semibold text-gray-800 bg-transparent border border-transparent rounded-md px-2 py-1 hover:border-gray-200 hover:bg-gray-50 focus:border-[var(--brand)] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[var(--brand)]/20 transition-all"
                                />

                                <span class="text-[11px] text-gray-400 mr-1">
                                    {{ Workflow::where('stage', $stage['key'])->count() }} items
                                </span>

                                <button
                                    type="button"
                                    wire:click="$dispatch('open-confirm', { title: 'Delete Stage?', message: 'Items currently in this stage will not be lost, but the stage will be removed.', type: 'warning', action: 'deleteStage', params: [{{ $stage['id'] }}] })"
                                    aria-label="Delete stage"
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
                                    type="button"
                                    @click="open = !open"
                                    aria-label="Pick stage color"
                                    class="h-9 w-9 rounded-lg border-2 border-white shadow-sm flex-shrink-0 transition-transform hover:opacity-90 hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-300"
                                    style="background-color: {{ $newStageColor }}"
                                ></button>
                                <div
                                    x-show="open"
                                    x-cloak
                                    @click.away="open = false"
                                    x-transition
                                    class="absolute bottom-12 left-0 z-30 bg-white rounded-xl border border-gray-100 shadow-lg p-3 w-[240px]"
                                >
                                    <p class="text-[10px] uppercase tracking-wide font-semibold text-gray-400 mb-2">Preset</p>
                                    <div class="grid grid-cols-5 gap-2">
                                        @foreach ($this->presetColors as $color)
                                            <button
                                                type="button"
                                                class="h-7 w-7 rounded-lg border-2 transition-all hover:scale-110 {{ strtolower($newStageColor) === strtolower($color) ? 'border-gray-900 ring-2 ring-gray-200' : 'border-white' }}"
                                                style="background-color: {{ $color }}"
                                                aria-label="Set color {{ $color }}"
                                                wire:click="$set('newStageColor', '{{ $color }}')"
                                            ></button>
                                        @endforeach
                                    </div>
                                    <div class="mt-3 pt-3 border-t border-gray-100">
                                        <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                                            <span
                                                class="text-[10px] uppercase tracking-wide font-semibold text-gray-400"
                                                >Custom</span
                                            >
                                            <input
                                                type="color"
                                                wire:model.live="newStageColor"
                                                class="h-7 w-10 rounded cursor-pointer border border-gray-200"
                                                aria-label="Pick custom color"
                                            />
                                            <span
                                                class="text-gray-500 font-mono text-[11px]"
                                                >{{ strtoupper($newStageColor) }}</span
                                            >
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <input
                                type="text"
                                wire:model="newStageName"
                                class="form-input flex-1"
                                placeholder="Stage name"
                                x-on:keydown.enter.prevent="$wire.addStage()"
                            />

                            <button wire:click="addStage" class="btn btn-primary">
                                <i class="fas fa-plus text-xs"></i> Add
                            </button>
                        </div>
                        @error ('newStageName')
                            <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
