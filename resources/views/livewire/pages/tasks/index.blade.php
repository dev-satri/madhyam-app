<?php

use App\Models\Comment;
use App\Models\File;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCommentNotification;
use App\Notifications\TaskCompletedNotification;
use App\Services\ActivityLogger;
use App\Services\RbacService;
use App\Support\UserVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;
    use WithFileUploads;

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

    public string $commentAttachments = '[]';

    public array $formAttachments = [];
    public string $formAttachmentsJson = '[]';

    public $newFileUpload = null;



    public function mount(): void
    {
        $this->formDueDate = now()->format('Y-m-d');

        // Pre-fill from workflow redirect (query params)
        if (request()->has('workflow_id')) {
            $wfId = (int) request()->query('workflow_id');
            $wf = DB::table('workflows')->where('id', $wfId)->first();
            if ($wf) {
                $this->formWorkflowId = $wfId;
                $this->formTitle = request()->query('workflow_title', $wf->title);
                $this->formClientId = (int) ($wf->client_id ?? 0);
                $this->formType = $wf->type ?? 'task';
                $this->showForm = true;
            }
        }
    }

    public function updatedFormAttachmentsJson(string $value): void
    {
        $this->formAttachments = json_decode($value, true) ?: [];
    }

    public function getAvailableWorkflows(): Collection
    {
        if (! $this->formClientId) {
            return collect();
        }

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
        if (! $user) {
            return false;
        }
        $rbac = app(RbacService::class);

        return $rbac->hasDataAccess($user->role, 'canAddTasks');
    }

    #[Computed]
    public function team()
    {
        return UserVisibility::apply(
            DB::table('users')->where('status', 'active')
        )->orderBy('name')->get();
    }

    public function getFilteredTasks()
    {
        $q = Task::query()
            ->leftJoin('clients', 'tasks.client_id', '=', 'clients.id')
            ->leftJoin('users', 'tasks.assignee', '=', 'users.id');

        $user = Auth::user();
        $rbac = app(RbacService::class);
        if ($user && ! $rbac->hasDataAccess($user->role, 'seeAllTasks')) {
            $q->where('tasks.assignee', $user->id);
        }

        if ($this->search) {
            $q->where(function ($q) {
                $q->where('tasks.title', 'like', "%{$this->search}%")->orWhere('tasks.description', 'like', "%{$this->search}%");
            });
        }
        if ($this->statusFilter) {
            $q->where('tasks.status', $this->statusFilter);
        }
        if ($this->typeFilter) {
            $q->where('tasks.type', $this->typeFilter);
        }
        if ($this->priorityFilter) {
            $q->where('tasks.priority', $this->priorityFilter);
        }
        if ($this->clientFilter) {
            $q->where('tasks.client_id', $this->clientFilter);
        }
        if ($this->tab !== 'all') {
            $q->where('tasks.type', $this->tab);
        }

        return $q->select('tasks.*', 'clients.name as client_name', 'users.name as assignee_name')
            ->orderBy('tasks.due_date', 'asc')
            ->paginate(15);
    }

    public function getStats(): array
    {
        $all = Task::query();

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
        $task = Task::find($id);
        if (! $task) {
            return;
        }

        // Lock if linked workflow is published or ready-for-production
        if ($task->workflow_id) {
            $workflow = DB::table('workflows')->where('id', $task->workflow_id)->first();
            if ($workflow && in_array($workflow->stage, ['published', 'ready-for-production'])) {
                $this->dispatch('toast', message: 'Cannot update task — workflow is in a terminal state', type: 'error');

                return;
            }
        }

        $next = match ($task->status) {
            'todo' => 'in-progress',
            'in-progress' => 'completed',
            default => 'todo',
        };
        $task->status = $next;
        $task->save();

        // Notify managers when a task transitions to completed. Uses SkipsSelfActor
        // so a manager who completed their own task isn't notified back.
        if ($next === 'completed') {
            $managers = User::where('role', 'manager')->where('status', 'active')->get();
            if ($managers->isNotEmpty()) {
                Notification::send($managers, new TaskCompletedNotification($task, Auth::user()));
            }
        }

        $this->dispatch('toast', message: "Status changed to $next", type: 'success');
    }

    public function openForm(?int $id = null): void
    {
        if ($id) {
            $t = Task::find($id);
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
                $raw = $t->attachments;
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $this->formAttachments = is_array($decoded) ? $decoded : [];
                } else {
                    $this->formAttachments = is_array($raw) ? $raw : [];
                }
                $this->formAttachmentsJson = json_encode($this->formAttachments);
            }
        } else {
            $this->editingId = 0;
            $this->reset(['formTitle', 'formDescription', 'formType', 'formPriority', 'formClientId', 'formWorkflowId', 'formAssigneeId', 'formDueDate', 'formLocation', 'formChecklist', 'formNotes']);
            $this->formType = 'task';
            $this->formPriority = 'medium';
            $this->formDueDate = now()->format('Y-m-d');
            $this->formAttachments = [];
            $this->formAttachmentsJson = '[]';
        }
        $this->showForm = true;
    }

    public function save(): void
    {
        // Lock if linked workflow is published or ready-for-production
        if ($this->editingId) {
            $existingTask = Task::find($this->editingId);
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
            'attachments' => $this->formAttachments ? array_values($this->formAttachments) : null,
        ];

        $priorAssignee = null;
        if ($this->editingId) {
            $task = Task::findOrFail($this->editingId);
            $priorAssignee = $task->assignee;
            $task->update($data);
            $taskId = $this->editingId;
            $verb = 'updated';
        } else {
            $data['status'] = 'todo';
            $data['progress'] = 0;
            $task = Task::create($data);
            $taskId = $task->id;
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
        $this->formAttachments = [];
        $this->formAttachmentsJson = '[]';
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
        return Task::query()
            ->leftJoin('clients', 'tasks.client_id', '=', 'clients.id')
            ->leftJoin('users', 'tasks.assignee', '=', 'users.id')
            ->select('tasks.*', 'clients.name as client_name', 'users.name as assignee_name')
            ->where('tasks.id', $this->detailId)
            ->first();
    }

    public function getDetailAttachments(): array
    {
        if (!$this->detailId) return [];
        $atts = [];

        $task = Task::find($this->detailId);
        if ($task && !is_null($task->attachments)) {
            $raw = $task->attachments;
            if (is_string($raw)) $raw = json_decode($raw, true);
            if (is_array($raw)) $atts = array_values(array_filter($raw, fn($a) => is_array($a)));
        }

        if ($task && $task->workflow_id) {
            $wfAtts = DB::table('workflows')->where('id', $task->workflow_id)->value('attachments');
            if (!empty($wfAtts)) {
                if (is_string($wfAtts)) $wfAtts = json_decode($wfAtts, true);
                if (is_array($wfAtts)) {
                    $atts = array_merge($atts, array_values(array_filter($wfAtts, fn($a) => is_array($a))));
                }
            }
            $contentId = DB::table('workflows')->where('id', $task->workflow_id)->value('content_id');
            if ($contentId) {
                $contentAtts = DB::table('contents')->where('id', $contentId)->value('attachments');
                if (!empty($contentAtts)) {
                    if (is_string($contentAtts)) $contentAtts = json_decode($contentAtts, true);
                    if (is_array($contentAtts)) {
                        $atts = array_merge($atts, array_values(array_filter($contentAtts, fn($a) => is_array($a))));
                    }
                }
            }
        }

        $atts = array_map(function ($a) {
            if (empty($a['url']) && !empty($a['id'])) {
                $file = DB::table('files')->where('id', $a['id'])->first();
                if ($file && $file->path) {
                    $a['url'] = \Illuminate\Support\Facades\Storage::url($file->path);
                }
            }
            return $a;
        }, $atts);

        return array_values(array_filter($atts, fn($a) => !empty($a['url'])));
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
        if (! $this->commentText || ! $this->detailId) {
            return;
        }
        $commentId = DB::table('task_comments')->insertGetId([
            'task_id' => $this->detailId,
            'user_id' => Auth::id(),
            'text' => $this->commentText,
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

    public function getDiscussionComments()
    {
        if (! $this->detailId) {
            return collect();
        }

        return Comment::with('user')
            ->where('commentable_type', Task::class)
            ->where('commentable_id', $this->detailId)
            ->latest()
            ->get();
    }

    public function addDiscussionComment(string $attachmentsJson = '[]'): void
    {
        $hasText = trim($this->commentText) !== '';
        $attachments = json_decode($attachmentsJson, true) ?: [];
        $hasFiles = !empty($attachments);

        if (!$hasText && !$hasFiles) {
            return;
        }

        if (! $this->detailId) {
            return;
        }

        if ($hasFiles && !$hasText) {
            $task = Task::findOrFail($this->detailId);
            $existing = $task->attachments ?: [];
            if (is_string($existing)) {
                $existing = json_decode($existing, true) ?: [];
            }
            $merged = array_values(array_merge($existing, $attachments));

            $task->update([
                'attachments' => $merged,
            ]);

            $this->commentAttachments = '[]';
            $this->resetPage();
            $this->dispatch('toast', message: 'Files attached', type: 'success');
            return;
        }

        Comment::create([
            'commentable_type' => Task::class,
            'commentable_id' => $this->detailId,
            'user_id' => Auth::id(),
            'body' => $this->commentText,
            'attachments' => $attachments ?: null,
        ]);

        app(ActivityLogger::class)->record(Auth::user(), "Commented on task #{$this->detailId}");

        $this->commentText = '';
        $this->commentAttachments = '[]';
        $this->dispatch('tiptap-set-content', name: 'taskComment', html: '');
        $this->dispatch('toast', message: 'Comment added', type: 'success');
    }

    public function getPickableFiles(?string $search = null, ?int $clientId = null): array
    {
        $q = File::select('id', 'name', 'type', 'size')
            ->orderBy('name');

        if ($clientId) {
            $q->where('client_id', $clientId);
        }
        if ($search) {
            $q->where('name', 'like', "%{$search}%");
        }

        return $q->get()->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->name,
            'url' => $f->getUrl(),
            'type' => $f->type,
            'size_label' => $f->size_readable,
        ])->toArray();
    }

    public function updatedNewFileUpload(): void
    {
        if (!$this->newFileUpload) return;

        $file = $this->newFileUpload;
        $path = $file->store('files/' . now()->format('Y/m'), 'public');

        $ext = strtolower($file->getClientOriginalExtension());
        $typeMap = ['jpg'=>'image','jpeg'=>'image','png'=>'image','gif'=>'image','webp'=>'image','svg'=>'image',
            'mp4'=>'video','mov'=>'video','avi'=>'video','webm'=>'video','mkv'=>'video',
            'mp3'=>'audio','wav'=>'audio','ogg'=>'audio','aac'=>'audio','m4a'=>'audio'];
        $fileType = $typeMap[$ext] ?? 'document';

        $fileModel = File::create([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'type' => $fileType,
            'size' => $file->getSize(),
            'storage_type' => 'local',
            'uploaded_by' => auth()->id(),
            'client_id' => $this->formClientId ?: null,
        ]);

        $att = [
            'id' => $fileModel->id,
            'name' => $fileModel->name,
            'url' => $fileModel->getUrl(),
            'type' => $fileModel->type,
        ];

        $this->formAttachments[] = $att;
        $this->formAttachmentsJson = json_encode($this->formAttachments);
        $this->newFileUpload = null;
        $this->dispatch('toast', message: 'File uploaded and attached', type: 'success');
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

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6" x-data>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div><h1 class="text-2xl font-extrabold text-gray-900">Videos & Shoots</h1><p class="text-sm text-gray-500">Manage videos, shoots, and editing work</p></div>
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
                <div><label class="form-label">Search</label><x-search-input wire="search" placeholder="Search tasks..." /></div>
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
                                <td class="font-medium">
                                    {{ $t->title }}
                                    @php
                                        $hasTaskAttachments = false;
                                        if ($t->attachments) {
                                            $taskAtts = is_string($t->attachments) ? json_decode($t->attachments, true) : $t->attachments;
                                            $hasTaskAttachments = is_array($taskAtts) && count($taskAtts) > 0;
                                        }
                                    @endphp
                                    @if($hasTaskAttachments)
                                        <i class="fas fa-paperclip text-xs text-gray-400 ml-1" title="{{ count($taskAtts) }} attachment(s)"></i>
                                    @endif
                                </td>
                                <td class="text-gray-500">{{ $t->client_name ?? '—' }}</td>
                                <td><span class="badge badge-{{ $t->type }}">{{ ucfirst($t->type) }}</span></td>
                                <td><span class="badge badge-{{ $t->priority }}">{{ ucfirst($t->priority) }}</span></td>
                                <td class="text-gray-500">{{ $t->assignee_name ?? '—' }}</td>
                                <td class="{{ $t->due_date && \Carbon\Carbon::parse($t->due_date)->isPast() && $t->status!=='completed' ? 'text-red-600 font-semibold' : '' }}">{{ $t->due_date ? \App\Support\NepaliDate::display($t->due_date) : '—' }}</td>
                                <td>
                                    @php
                                        $nextStatus = match($t->status) { 'todo' => 'In Progress', 'in-progress' => 'Completed', default => 'To Do' };
                                        $nextStatusKey = match($t->status) { 'todo' => 'in-progress', 'in-progress' => 'completed', default => 'todo' };
                                    @endphp
                                    <button wire:click="$dispatch('open-confirm', { title: 'Change Status?', message: 'Move ' + {{ \Illuminate\Support\Js::from($t->title) }} + ' to {{ $nextStatus }}?', type: 'info', action: 'cycleStatus', params: [{{ $t->id }}] })" class="badge badge-{{ str_replace('-','-',$t->status) }} cursor-pointer hover:shadow-sm">{{ ucwords(str_replace('-',' ',$t->status)) }}</button>
                                </td>
                                <td class="flex gap-1"><button wire:click="openDetail({{ $t->id }})" wire:loading.attr="disabled" wire:target="openDetail({{ $t->id }})" class="btn btn-ghost btn-sm btn-icon"><i class="fas fa-eye text-xs" wire:loading.remove wire:target="openDetail({{ $t->id }})"></i><i class="fas fa-spinner fa-spin text-xs" wire:loading wire:target="openDetail({{ $t->id }})"></i></button><button wire:click="openForm({{ $t->id }})" class="btn btn-ghost btn-sm btn-icon"><i class="fas fa-pen text-xs"></i></button>@if($t->status !== 'in-progress')<button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Task?', message: 'This task and its checklist will be permanently removed.', type: 'danger', action: 'deleteTask', params: [{{ $t->id }}] })" class="btn btn-ghost btn-sm btn-icon text-red-500" aria-label="Delete task"><i class="fas fa-trash text-xs"></i></button>@endif</td>
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
                        {{-- Attachments — prominent, top of form --}}
                        <div class="border border-dashed border-gray-200 rounded-xl bg-gray-50/50 p-4">
                            <label class="form-label mb-2"><i class="fas fa-paperclip text-gray-400 mr-1"></i> Attachments</label>
                            <x-file-picker :clientId="$formClientId ?: null" wire="formAttachmentsJson" :initial="$formAttachments" wireClientId="formClientId" />
                            @if ($this->editingId && count($formAttachments) > 0)
                                <div class="mt-3">
                                    @include('livewire.partials.attachment-display', ['attachments' => $formAttachments, 'label' => ''])
                                </div>
                            @endif
                        </div>
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
                        <div><label class="form-label">Due Date</label><x-date-input model="formDueDate" name="formDueDate" /></div>
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

            {{-- ========== TASK DETAIL MODAL ========== --}}
            @if($showDetail)
            @php $task = $this->getDetailTask(); @endphp
            @if($task)
            @php
                $taskPriorityBadge = match($task->priority) {
                    'urgent' => 'bg-red-100 text-red-700 border-red-200',
                    'high'   => 'bg-orange-100 text-orange-700 border-orange-200',
                    'medium' => 'bg-blue-100 text-blue-700 border-blue-200',
                    default  => 'bg-gray-100 text-gray-600 border-gray-200',
                };
                $taskTypeBadge = match($task->type) {
                    'shoot'   => 'bg-purple-50 text-purple-700 border-purple-200',
                    'editing' => 'bg-amber-50 text-amber-700 border-amber-200',
                    default   => 'bg-blue-50 text-blue-700 border-blue-200',
                };
                $taskStatusBadge = match($task->status) {
                    'completed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'in-progress' => 'bg-blue-50 text-blue-700 border-blue-200',
                    default => 'bg-gray-50 text-gray-600 border-gray-200',
                };
                $isTaskOverdue = $task->due_date && \Carbon\Carbon::parse($task->due_date)->isPast() && $task->status !== 'completed';
            @endphp
            <div wire:transition.opacity.duration.150ms class="modal-overlay" x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">
                <div class="modal-box max-w-2xl" x-on:click.stop>

                    {{-- Header --}}
                    <div class="modal-header border-b border-gray-100 pb-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5 mb-1.5">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $taskTypeBadge }}">{{ ucfirst($task->type) }}</span>
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $taskPriorityBadge }}">{{ ucfirst($task->priority) }}</span>
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $taskStatusBadge }}">{{ ucwords(str_replace('-', ' ', $task->status)) }}</span>
                                @if ($isTaskOverdue)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-red-100 text-red-700 px-1.5 py-0.5 text-[10px] font-semibold">
                                        <i class="fas fa-exclamation-circle text-[9px]"></i> Overdue
                                    </span>
                                @endif
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 leading-snug">{{ $task->title }}</h3>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button wire:click="$set('showDetail', false)" class="btn btn-ghost btn-icon btn-sm" aria-label="Close"><i class="fas fa-times"></i></button>
                        </div>
                    </div>

                    <div class="modal-body space-y-4 max-h-[70vh] overflow-y-auto">

                        {{-- Description --}}
                        @if ($task->description)
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Description</p>
                                <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $task->description }}</p>
                            </div>
                        @endif

                        {{-- Metadata --}}
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Client</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-building text-gray-400"></i> {{ $task->client_name ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Assignee</p>
                                @if ($task->assignee_name)
                                    <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-user text-gray-400"></i> {{ $task->assignee_name }}</p>
                                @else
                                    <p class="text-gray-400 italic text-xs">Unassigned</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Due Date</p>
                                @if ($task->due_date)
                                    <p class="{{ $isTaskOverdue ? 'text-red-600 font-semibold' : 'text-gray-800' }} text-xs flex items-center gap-1.5">
                                        <i class="fas fa-calendar-alt {{ $isTaskOverdue ? 'text-red-500' : 'text-gray-400' }}"></i>
                                        {{ \App\Support\NepaliDate::display($task->due_date) }}
                                    </p>
                                @else
                                    <p class="text-gray-400 italic text-xs">No due date</p>
                                @endif
                            </div>
                            @if ($task->workflow_id)
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Linked Workflow</p>
                                    <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-link text-gray-400"></i> Workflow #{{ $task->workflow_id }}</p>
                                </div>
                            @endif
                            @if ($task->location)
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Location</p>
                                    <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-map-marker-alt text-gray-400"></i> {{ $task->location }}</p>
                                </div>
                            @endif
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Created</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-clock text-gray-400"></i> {{ $task->created_at ? \App\Support\NepaliDate::display($task->created_at) : '—' }}</p>
                            </div>
                        </div>

                        {{-- Checklist (shoot tasks only) --}}
                        @if ($task->type === 'shoot' && $task->checklist)
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1.5">Checklist</p>
                                <div class="space-y-1.5">
                                    @foreach(explode("\n", $task->checklist) as $line)
                                        @php $line = trim($line); @endphp
                                        @if($line !== '')
                                            <div class="flex items-start gap-2 text-sm text-gray-700">
                                                <i class="fas fa-circle text-[4px] text-gray-300 mt-1.5 shrink-0"></i>
                                                <span>{{ $line }}</span>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Attachments --}}
                        @php $tAtts = $this->getDetailAttachments(); @endphp
                        @if (count($tAtts) > 0)
                            <div>
                                @include('livewire.partials.attachment-display', ['attachments' => $tAtts, 'label' => 'Attachments'])
                            </div>
                        @endif

                        {{-- Discussion --}}
                        <div class="border-t border-gray-100 pt-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fas fa-comments text-gray-400"></i> Discussion
                            </h4>

                            @php $discussionComments = $this->getDiscussionComments(); @endphp
                            <div class="space-y-3 max-h-48 overflow-y-auto mb-3">
                                @forelse($discussionComments as $comment)
                                    <div class="flex gap-2.5">
                                        <div class="flex-shrink-0 w-6 h-6 rounded-full bg-[rgba(var(--brand-rgb),0.1)] flex items-center justify-center text-[9px] font-bold text-[var(--brand)]">
                                            {{ strtoupper(substr($comment->user->name ?? '?', 0, 1)) }}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-0.5">
                                                <span class="text-xs font-semibold text-gray-800">{{ $comment->user->name ?? 'Unknown' }}</span>
                                                <span class="text-[10px] text-gray-400">{{ $comment->created_at->diffForHumans() }}</span>
                                            </div>
                                            <div class="comment-body text-sm text-gray-600">{!! $comment->body !!}</div>
                                            @if($comment->attachments)
                                                <div class="flex flex-wrap gap-1 mt-1">
                                                    @foreach($comment->attachments as $att)
                                                        @if(($att['type'] ?? '') === 'image')
                                                            <a href="{{ $att['url'] }}" target="_blank" class="block"><img src="{{ $att['url'] }}" class="rounded-lg max-h-20 border border-gray-100"></a>
                                                        @else
                                                            <a href="{{ $att['url'] }}" target="_blank" class="inline-flex items-center gap-1 bg-gray-100 rounded-lg px-2 py-1 text-xs text-gray-600 hover:bg-gray-200">
                                                                <i class="fas {{ ($att['type'] ?? '') === 'drive' ? 'fa-google-drive text-blue-500' : 'fa-file text-gray-400' }}"></i>
                                                                {{ $att['name'] ?? 'File' }}
                                                            </a>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-xs text-gray-400 text-center py-2">No comments yet. Start the discussion.</p>
                                @endforelse
                            </div>

                            {{-- Comment form --}}
                            <div class="border-t border-gray-100 pt-3">
                                <x-tiptap-editor wire="commentText" name="taskComment" placeholder="Add a comment..." />
                                <div class="flex items-center justify-between mt-2">
                                    <x-file-picker :clientId="$task->client_id ?? null" wire="commentAttachments" :initial="$commentAttachments" />
                                    <button @click="$wire.addDiscussionComment($wire.get('commentAttachments'))"
                                            wire:loading.attr="disabled"
                                            class="btn btn-primary btn-sm"
                                            x-data>
                                        <i class="fas fa-paper-plane text-xs" wire:loading.remove wire:target="addDiscussionComment"></i>
                                        <i class="fas fa-spinner fa-spin text-xs" wire:loading wire:target="addDiscussionComment"></i>
                                        Send
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
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
