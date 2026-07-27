<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCommentNotification;
use App\Notifications\TaskCompletedNotification;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\RbacService;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $typeFilter = '';
    public string $priorityFilter = '';
    public string $clientFilter = '';
    public string $tab = 'all';
    public bool $showForm = false;
    public bool $showDetail = false;
    public int $editingId = 0;
    public int $detailId = 0;
    public string $formTitle = '';
    public string $formDescription = '';
    public string $formType = 'task';
    public string $formPriority = 'medium';
    public int $formClientId = 0;
    public ?int $formWorkflowId = null;
    public int $formAssigneeId = 0;
    public string $formDueDate = '';
    public string $formLocation = '';
    public string $formChecklist = '';
    public string $formNotes = '';
    public array $selected = [];
    public string $bulkStatus = '';
    public int $bulkAssigneeId = 0;
    public string $commentText = '';

    public function mount(): void
    {
        $this->formDueDate = now()->format('Y-m-d');
    }

    public function getAvailableWorkflows(): \Illuminate\Support\Collection
    {
        if (!$this->formClientId) return collect();
        return DB::table('workflows')
            ->where('client_id', $this->formClientId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    #[Computed]
    public function tasks()
    {
        return $this->getFilteredTasks();
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
    public function canAddTasks(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        $rbac = app(RbacService::class);
        return $rbac->hasDataAccess($user->role, 'canAddTasks');
    }

    #[Computed]
    public function team()
    {
        return DB::table('users')->where('status', 'active')->orderBy('name')->get();
    }

    public function getFilteredTasks()
    {
        $q = DB::table('tasks')->leftJoin('clients', 'tasks.client_id', '=', 'clients.id')->leftJoin('users', 'tasks.assignee', '=', 'users.id');

        $user = Auth::user();
        $rbac = app(RbacService::class);
        if ($user && !$rbac->hasDataAccess($user->role, 'seeAllTasks')) {
            $q->where('tasks.assignee', $user->id);
        }

        if ($this->search) {
            $q->where(function ($q) {
                $q->where('tasks.title', 'like', "%{$this->search}%")->orWhere('tasks.description', 'like', "%{$this->search}%");
            });
        }
        if ($this->statusFilter) $q->where('tasks.status', $this->statusFilter);
        if ($this->typeFilter) $q->where('tasks.type', $this->typeFilter);
        if ($this->priorityFilter) $q->where('tasks.priority', $this->priorityFilter);
        if ($this->clientFilter) $q->where('tasks.client_id', $this->clientFilter);
        if ($this->tab !== 'all') $q->where('tasks.type', $this->tab);

        return $q->select('tasks.*', 'clients.name as client_name', 'users.name as assignee_name')
            ->orderBy('tasks.due_date', 'asc')
            ->paginate(15);
    }

    public function getStats(): array
    {
        $all = DB::table('tasks');
        return [
            'total' => $all->count(),
            'todo' => (clone $all)->where('status', 'todo')->count(),
            'in_progress' => (clone $all)->where('status', 'in-progress')->count(),
            'completed' => (clone $all)->where('status', 'completed')->count(),
            'overdue' => (clone $all)->where('due_date', '<', now())->where('status', '!=', 'completed')->count(),
        ];
    }

    public function cycleStatus(int $id): void
    {
        $task = DB::table('tasks')->where('id', $id)->first();
        if (!$task) return;

        // Lock if linked workflow is published or ready-for-production
        if ($task->workflow_id) {
            $workflow = DB::table('workflows')->where('id', $task->workflow_id)->first();
            if ($workflow && in_array($workflow->stage, ['published', 'ready-for-production'])) {
                $this->dispatch('toast', message: 'Cannot update task — workflow is in a terminal state', type: 'error');
                return;
            }
        }

        $next = match($task->status) {
            'todo' => 'in-progress',
            'in-progress' => 'completed',
            default => 'todo',
        };
        DB::table('tasks')->where('id', $id)->update(['status' => $next]);

        // Notify managers when a task transitions to completed. Uses SkipsSelfActor
        // so a manager who completed their own task isn't notified back.
        if ($next === 'completed' && $model = Task::find($id)) {
            $managers = User::where('role', 'manager')->where('status', 'active')->get();
            if ($managers->isNotEmpty()) {
                Notification::send($managers, new TaskCompletedNotification($model, Auth::user()));
            }
        }

        $this->dispatch('toast', message: "Status changed to $next", type: 'success');
    }

    public function openForm(?int $id = null): void
    {
        if ($id) {
            $t = DB::table('tasks')->where('id', $id)->first();
            if ($t) {
                $this->editingId = $t->id;
                $this->formTitle = $t->title;
                $this->formDescription = $t->description ?? '';
                $this->formType = $t->type;
                $this->formPriority = $t->priority;
                $this->formClientId = $t->client_id ?? 0;
                $this->formWorkflowId = $t->workflow_id ?? null;
                $this->formAssigneeId = $t->assignee ?? 0;
                $this->formDueDate = $t->due_date ?? '';
                $this->formLocation = $t->location ?? '';
                $this->formChecklist = $t->checklist ?? '';
                $this->formNotes = $t->submission_notes ?? '';
            }
        } else {
            $this->editingId = 0;
            $this->reset(['formTitle', 'formDescription', 'formType', 'formPriority', 'formClientId', 'formWorkflowId', 'formAssigneeId', 'formDueDate', 'formLocation', 'formChecklist', 'formNotes']);
            $this->formType = 'task';
            $this->formPriority = 'medium';
            $this->formDueDate = now()->format('Y-m-d');
        }
        $this->showForm = true;
    }

    public function save(): void
    {
        // Lock if linked workflow is published or ready-for-production
        if ($this->editingId) {
            $existingTask = DB::table('tasks')->where('id', $this->editingId)->first();
            if ($existingTask && $existingTask->workflow_id) {
                $workflow = DB::table('workflows')->where('id', $existingTask->workflow_id)->first();
                if ($workflow && in_array($workflow->stage, ['published', 'ready-for-production'])) {
                    $this->dispatch('toast', message: 'Cannot edit task — workflow is in a terminal state', type: 'error');
                    return;
                }
            }
        }

        $this->validate([
            'formTitle' => 'required|string|max:255',
            'formType' => 'required|in:task,shoot,editing',
            'formPriority' => 'required|in:low,medium,high,urgent',
            'formDueDate' => 'nullable|date|after_or_equal:today',
        ], [
            'formDueDate.after_or_equal' => 'Due date must be today or a future date',
        ]);

        $data = [
            'title' => $this->formTitle,
            'description' => $this->formDescription,
            'type' => $this->formType,
            'priority' => $this->formPriority,
            'client_id' => $this->formClientId ?: null,
            'workflow_id' => $this->formWorkflowId ?: null,
            'assignee' => $this->formAssigneeId ?: null,
            'due_date' => $this->formDueDate ?: null,
            'location' => $this->formType === 'shoot' ? $this->formLocation : null,
            'checklist' => $this->formType === 'shoot' ? $this->formChecklist : null,
            'submission_notes' => $this->formNotes ?: null,
        ];

        $data['updated_at'] = now();
        $priorAssignee = null;
        if ($this->editingId) {
            $priorAssignee = DB::table('tasks')->where('id', $this->editingId)->value('assignee');
            DB::table('tasks')->where('id', $this->editingId)->update($data);
            $taskId = $this->editingId;
            $verb = 'updated';
        } else {
            $data['status'] = 'todo';
            $data['progress'] = 0;
            $data['created_at'] = now();
            $taskId = DB::table('tasks')->insertGetId($data);
            $verb = 'created';
        }

        app(ActivityLogger::class)->record(Auth::user(), "Task '{$this->formTitle}' {$verb}");

        // Notify assignee if this is a new task or the assignee changed.
        // SkipsSelfActor drops the send when the assigner assigned to themself.
        if ($data['assignee'] && $data['assignee'] != $priorAssignee) {
            $model = Task::find($taskId);
            $assignee = User::find($data['assignee']);
            if ($model && $assignee) {
                $assignee->notify(new TaskAssignedNotification($model, Auth::user()));
            }
        }

        $this->showForm = false;
        $this->dispatch('toast', message: $this->editingId ? 'Task updated' : 'Task created', type: 'success');
        $this->resetPage();
    }

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $this->showDetail = true;
        $this->commentText = '';
    }

    public function getDetailTask()
    {
        return DB::table('tasks')->leftJoin('clients', 'tasks.client_id', '=', 'clients.id')->leftJoin('users', 'tasks.assignee', '=', 'users.id')
            ->select('tasks.*', 'clients.name as client_name', 'users.name as assignee_name')
            ->where('tasks.id', $this->detailId)->first();
    }

    public function getDetailComments()
    {
        return DB::table('task_comments')->join('users', 'task_comments.user_id', '=', 'users.id')
            ->where('task_comments.task_id', $this->detailId)
            ->select('task_comments.*', 'users.name as user_name')
            ->orderBy('task_comments.created_at', 'asc')->get();
    }

    public function addComment(): void
    {
        if (!$this->commentText || !$this->detailId) return;
        $commentId = DB::table('task_comments')->insertGetId([
            'task_id'    => $this->detailId,
            'user_id'    => Auth::id(),
            'text'       => $this->commentText,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(ActivityLogger::class)->record(Auth::user(), "Commented on task #{$this->detailId}");

        // Notify the assignee + every distinct prior commenter, excluding the author.
        // SkipsSelfActor also protects against the assignee commenting on their own task.
        $comment = TaskComment::find($commentId);
        $task = $comment?->task;
        if ($comment && $task) {
            $recipientIds = collect([$task->assignee])
                ->merge(
                    DB::table('task_comments')
                        ->where('task_id', $this->detailId)
                        ->where('id', '!=', $commentId)
                        ->pluck('user_id')
                )
                ->filter()
                ->reject(fn ($id) => (int) $id === (int) Auth::id())
                ->unique()
                ->values();

            if ($recipientIds->isNotEmpty()) {
                $recipients = User::whereIn('id', $recipientIds)->where('status', 'active')->get();
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new TaskCommentNotification($comment, Auth::user()));
                }
            }
        }

        $this->commentText = '';
        $this->dispatch('toast', message: 'Comment added', type: 'success');
    }

    public function deleteTask(int $id): void
    {
        $task = Task::findOrFail($id);
        if ($task->status === 'in-progress') {
            $this->dispatch('toast', message: 'Cannot delete a task that is in progress', type: 'error');
            return;
        }
        $task->delete();
        app(ActivityLogger::class)->record(Auth::user(), "Deleted task #{$id}");
        $this->resetPage();
        $this->dispatch('toast', message: 'Task deleted', type: 'success');
    }

    public function render(): mixed    {
        return <<<'blade'
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div><h1 class="text-2xl font-extrabold text-gray-900">Tasks & Shoots</h1><p class="text-sm text-gray-500">Manage tasks, shoots, and editing work</p></div>
                @if($this->canAddTasks)
                    <button wire:click="openForm" class="btn btn-primary"><i class="fas fa-plus text-sm"></i> Add Task</button>
                @endif
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                @foreach(['total'=>'Total','todo'=>'To Do','in_progress'=>'In Progress','completed'=>'Completed','overdue'=>'Overdue'] as $k=>$label)
                <div class="stat-card text-center py-3">
                    <p class="stat-value text-xl font-extrabold {{ $k==='overdue'?'text-red-600':($k==='completed'?'text-green-600':'text-gray-900') }}">{{ $this->stats[$k] }}</p>
                    <p class="stat-label text-[11px]">{{ $label }}</p>
                </div>
                @endforeach
            </div>

            {{-- Tabs --}}
            <div class="tab-group">
                @foreach(['all'=>'All','task'=>'Tasks','shoot'=>'Shoots','editing'=>'Editing'] as $key=>$label)
                <button wire:click="$set('tab','{{ $key }}')" class="tab-btn {{ $tab===$key?'active':'' }}">{{ $label }}</button>
                @endforeach
            </div>

            {{-- Filters --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                <div><label class="form-label">Search</label><div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i><input type="text" wire:model.live.debounce.250ms="search" class="form-input pl-10 focus:ring-0" placeholder="Search tasks..."></div></div>
                <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select"><option value="">All Status</option><option value="todo">To Do</option><option value="in-progress">In Progress</option><option value="completed">Completed</option></select></div>
                <div><label class="form-label">Priority</label><select wire:model.live="priorityFilter" class="form-select"><option value="">All Priority</option><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
                <div><label class="form-label">Client</label><select wire:model.live="clientFilter" class="form-select"><option value="">All Clients</option>@foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
            </div>

            {{-- Table --}}
            <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Title</th><th>Client</th><th>Type</th><th>Priority</th><th>Assignee</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            <!-- Skeleton Rows -->
                            @for($i = 0; $i < 5; $i++)
                                <tr wire:loading wire:target="search,statusFilter,priorityFilter,clientFilter,tab">
                                    <td><div class="skeleton h-4 w-32"></div></td>
                                    <td><div class="skeleton h-4 w-24"></div></td>
                                    <td><div class="skeleton h-5 w-16 rounded-full"></div></td>
                                    <td><div class="skeleton h-5 w-16 rounded-full"></div></td>
                                    <td><div class="skeleton h-4 w-24"></div></td>
                                    <td><div class="skeleton h-4 w-20"></div></td>
                                    <td><div class="skeleton h-5 w-20 rounded-full"></div></td>
                                    <td>
                                        <div class="flex gap-1">
                                            <div class="skeleton h-8 w-8 rounded-lg"></div>
                                            <div class="skeleton h-8 w-8 rounded-lg"></div>
                                            <div class="skeleton h-8 w-8 rounded-lg"></div>
                                        </div>
                                    </td>
                                </tr>
                            @endfor

                            @forelse($this->tasks as $t)
                            <tr wire:loading.remove wire:target="search,statusFilter,priorityFilter,clientFilter,tab">
                                <td class="font-medium">{{ $t->title }}</td>
                                <td class="text-gray-500">{{ $t->client_name ?? '—' }}</td>
                                <td><span class="badge badge-{{ $t->type }}">{{ ucfirst($t->type) }}</span></td>
                                <td><span class="badge badge-{{ $t->priority }}">{{ ucfirst($t->priority) }}</span></td>
                                <td class="text-gray-500">{{ $t->assignee_name ?? '—' }}</td>
                                <td class="{{ $t->due_date && \Carbon\Carbon::parse($t->due_date)->isPast() && $t->status!=='completed' ? 'text-red-600 font-semibold' : '' }}">{{ $t->due_date ? \Carbon\Carbon::parse($t->due_date)->format('M d, Y') : '—' }}</td>
                                <td><button wire:click="cycleStatus({{ $t->id }})" class="badge badge-{{ str_replace('-','-',$t->status) }} cursor-pointer hover:shadow-sm">{{ ucwords(str_replace('-',' ',$t->status)) }}</button></td>
                                <td class="flex gap-1"><button wire:click="openDetail({{ $t->id }})" class="btn btn-ghost btn-sm btn-icon"><i class="fas fa-eye text-xs"></i></button><button wire:click="openForm({{ $t->id }})" class="btn btn-ghost btn-sm btn-icon"><i class="fas fa-pen text-xs"></i></button>@if($t->status !== 'in-progress')<button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Task?', message: 'This task and its checklist will be permanently removed.', type: 'danger', action: 'deleteTask', params: [{{ $t->id }}] })" class="btn btn-ghost btn-sm btn-icon text-red-500" aria-label="Delete task"><i class="fas fa-trash text-xs"></i></button>@endif</td>
                            </tr>
                            @empty
                            <tr wire:loading.remove wire:target="search,statusFilter,priorityFilter,clientFilter,tab"><td colspan="8">
                                <div class="py-16 text-center">
                                    <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"><i class="fas fa-tasks text-2xl text-gray-300"></i></div>
                                    <p class="text-gray-500 font-medium text-sm">No tasks found</p>
                                    <p class="text-gray-400 text-xs mt-1">Create tasks and shoots to get started</p>
                                </div>
                            </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-gray-100">{{ $this->tasks->links() }}</div>
            </div>

            {{-- TaskForm Modal --}}
            @if($showForm)
            <div class="modal-overlay" wire:click.self="$set('showForm',false)" x-on:keydown.escape.window="$wire.set('showForm',false)">
                <div class="modal-box max-w-lg">
                    <div class="modal-header"><h3 class="text-base font-bold text-gray-900">{{ $editingId ? 'Edit Task' : 'New Task' }}</h3><button wire:click="$set('showForm',false)" class="btn btn-ghost btn-icon btn-sm"><i class="fas fa-times"></i></button></div>
                    <form wire:submit="save" class="modal-body space-y-4">
                        <div><label class="form-label">Title *</label><input type="text" wire:model="formTitle" class="form-input" required><span wire:error="formTitle" class="text-red-500 text-xs mt-1 block"></span></div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div><label class="form-label">Type</label><select wire:model="formType" class="form-select"><option value="task">Task</option><option value="shoot">Shoot</option><option value="editing">Editing</option></select></div>
                            <div><label class="form-label">Priority</label><select wire:model="formPriority" class="form-select"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div><label class="form-label">Client</label><select wire:model="formClientId" class="form-select"><option value="0">None</option>@foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                            <div><label class="form-label">Assignee</label><select wire:model="formAssigneeId" class="form-select"><option value="0">Unassigned</option>@foreach($this->team as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
                        </div>
                        @if ($formClientId)
                            <div><label class="form-label">Link to Workflow <span class="text-gray-400 text-xs">(optional)</span></label><select wire:model="formWorkflowId" class="form-select"><option value="">No linked workflow</option>@foreach($this->getAvailableWorkflows() as $wf)<option value="{{ $wf->id }}">{{ $wf->title }} — {{ ucfirst($wf->type) }} ({{ ucfirst($wf->stage) }})</option>@endforeach</select></div>
                        @endif
                        <div><label class="form-label">Due Date</label><input type="date" wire:model="formDueDate" class="form-input" min="{{ now()->format('Y-m-d') }}"></div>
                        @if($formType === 'shoot')
                        <div><label class="form-label">Location</label><input type="text" wire:model="formLocation" class="form-input" placeholder="Shoot location"></div>
                        <div><label class="form-label">Checklist</label><textarea wire:model="formChecklist" class="form-textarea" placeholder="One item per line"></textarea></div>
                        @endif
                        <div><label class="form-label">Description</label><textarea wire:model="formDescription" class="form-textarea" rows="3"></textarea></div>
                        <div class="flex gap-3 justify-end pt-2"><button type="button" wire:click="$set('showForm',false)" class="btn btn-secondary">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save text-sm"></i> Save</button></div>
                    </form>
                </div>
            </div>
            @endif

            {{-- TaskDetail Modal --}}
            @if($showDetail)
            @php $task = $this->getDetailTask(); $comments = $this->getDetailComments(); @endphp
            <div class="modal-overlay" wire:click.self="$set('showDetail',false)" x-on:keydown.escape.window="$wire.set('showDetail',false)">
                <div class="modal-box max-w-xl">
                    <div class="modal-header"><h3 class="text-base font-bold text-gray-900">{{ $task->title ?? '' }}</h3><button wire:click="$set('showDetail',false)" class="btn btn-ghost btn-icon btn-sm"><i class="fas fa-times"></i></button></div>
                    <div class="modal-body space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                            <div><span class="text-gray-500">Type:</span> <span class="badge badge-{{ $task->type ?? '' }}">{{ ucfirst($task->type ?? '') }}</span></div>
                            <div><span class="text-gray-500">Priority:</span> <span class="badge badge-{{ $task->priority ?? '' }}">{{ ucfirst($task->priority ?? '') }}</span></div>
                            <div><span class="text-gray-500">Status:</span> <span class="badge badge-{{ str_replace('-','-',$task->status ?? '') }}">{{ ucwords(str_replace('-',' ',$task->status ?? '')) }}</span></div>
                            <div><span class="text-gray-500">Due:</span> {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('M d, Y') : '—' }}</div>
                            <div><span class="text-gray-500">Client:</span> {{ $task->client_name ?? '—' }}</div>
                            <div><span class="text-gray-500">Assignee:</span> {{ $task->assignee_name ?? '—' }}</div>
                        </div>
                        @if($task->description)<div><h4 class="text-sm font-semibold text-gray-900 mb-1">Description</h4><p class="text-sm text-gray-600">{{ $task->description }}</p></div>@endif
                        @if($task->location)<div><span class="text-sm text-gray-500">Location:</span> {{ $task->location }}</div>@endif

                        {{-- Comments --}}
                        <div><h4 class="text-sm font-semibold text-gray-900 mb-2">Comments</h4>
                            <div class="space-y-2 max-h-40 overflow-y-auto">
                                @forelse($comments as $c)
                                <div class="bg-gray-50 rounded-lg px-3 py-2"><p class="text-sm font-medium text-gray-900">{{ $c->user_name }} <span class="text-[10px] text-gray-400 font-normal">{{ \Carbon\Carbon::parse($c->created_at)->diffForHumans() }}</span></p><p class="text-sm text-gray-600">{{ $c->text }}</p></div>
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

    #[On('confirm-resolved')]
    public function onConfirmResolved(string $action, array $params = []): void
    {
        if ($action !== '' && method_exists($this, $action)) {
            $this->{$action}(...$params);
        }
    }
};
