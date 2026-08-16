<?php

use App\Models\Client;
use App\Models\Comment;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Notifications\WorkflowAssignedNotification;
use App\Notifications\WorkflowAttachedNotification;
use App\Notifications\WorkflowCommentNotification;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\PackageService;
use App\Services\RbacService;
use App\Support\NepaliDate;
use App\Support\UserVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    protected static bool $syncingContent = false;

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

    public array $formAssigneeIds = [];

    public string $formStage = '';

    public ?string $formDeadline = null;

    public string $formTags = '';

    public array $formAttachments = [];

    public string $formAttachmentsJson = '[]';

    public bool $showStageManager = false;

    public array $stages = [];

    public string $newStageName = '';

    public string $newStageColor = '#4f46e5';

    public string $editingStageColor = '#4f46e5';

    public ?int $editingStageId = null;

    public bool $showDetail = false;

    public int $detailId = 0;

    // Revision modal
    public bool $showRevisionModal = false;

    public int $revisionWorkflowId = 0;

    public string $revisionNotes = '';

    // Resubmit
    public bool $showResubmitModal = false;

    public int $resubmitWorkflowId = 0;

    public array $presetColors = [
        '#4f46e5', '#7c3aed', '#a855f7', '#db2777', '#ec4899',
        '#f43f5e', '#dc2626', '#ea580c', '#f97316', '#eab308',
        '#ca8a04', '#84cc16', '#16a34a', '#10b981', '#14b8a6',
        '#06b6d4', '#0891b2', '#2563eb', '#3b82f6', '#6b7280',
    ];

    // Discussion
    public string $commentText = '';

    public string $commentAttachments = '[]';

    public $newFileUpload = null;

    public function mount(): void
    {
        $this->loadStages();
        if (! empty($this->stages) && ! $this->formStage) {
            $this->formStage = $this->stages[0]['key'] ?? '';
        }
    }

    public function updatedFormAttachmentsJson(string $value): void
    {
        $this->formAttachments = json_decode($value, true) ?: [];
    }

    public function loadStages(): void
    {
        $this->stages = WorkflowStage::orderBy('order')->get()->toArray();
    }

    public function getAvailableContent(): Collection
    {
        if (! $this->formClientId) {
            return collect();
        }

        return DB::table('contents')
            ->whereNull('deleted_at')
            ->where('client_id', $this->formClientId)
            ->orderBy('date', 'desc')
            ->get();
    }

    #[Computed]
    public function canMoveWorkflow(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }
        $rbac = app(RbacService::class);

        return $rbac->hasDataAccess($user->role, 'canMoveWorkflow');
    }

    #[Computed]
    public function canEditWorkflow(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }
        $rbac = app(RbacService::class);

        return $rbac->hasDataAccess($user->role, 'canEditWorkflow');
    }

    public function getStages()
    {
        return collect($this->stages);
    }

    public function getFilteredItems()
    {
        $query = Workflow::with(['client', 'stageInfo', 'content']);

        $user = Auth::user();
        $rbac = app(RbacService::class);
        if ($user && ! $rbac->hasDataAccess($user->role, 'seeAllWorkflow')) {
            $query->whereJsonContains('assignee', $user->id);
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

        return $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getItemsForStage(string $stageKey)
    {
        return $this->getFilteredItems()->filter(fn ($item) => $item->stage === $stageKey);
    }

    public function getApprovalStatus($workflow): array
    {
        $adminApproved = false;
        $clientApproved = false;

        if ($workflow->content_id) {
            $adminApproved = DB::table('approvals')
                ->whereNull('deleted_at')
                ->where('content_id', $workflow->content_id)
                ->where('approval_stage', 'admin-pending')
                ->where('status', 'approved')
                ->exists();
            $clientApproved = DB::table('approvals')
                ->whereNull('deleted_at')
                ->where('content_id', $workflow->content_id)
                ->where('approval_stage', 'client-pending')
                ->where('status', 'approved')
                ->exists();
        } else {
            $baseTitle = preg_replace('/\s*\([^)]*\)\s*$/', '', $workflow->title ?? '');
            if ($baseTitle) {
                $adminApproved = DB::table('approvals')
                    ->whereNull('deleted_at')
                    ->where('approval_stage', 'admin-pending')
                    ->where('status', 'approved')
                    ->where(function ($q) use ($baseTitle) {
                        $q->where('title', $baseTitle)
                            ->orWhere('title', 'like', $baseTitle . '%');
                    })
                    ->exists();
                $clientApproved = DB::table('approvals')
                    ->whereNull('deleted_at')
                    ->where('approval_stage', 'client-pending')
                    ->where('status', 'approved')
                    ->where(function ($q) use ($baseTitle) {
                        $q->where('title', $baseTitle)
                            ->orWhere('title', 'like', $baseTitle . '%');
                    })
                    ->exists();
            }
        }

        $isClient = ! empty($workflow->client_id);
        $ready = $isClient ? ($adminApproved && $clientApproved) : $adminApproved;

        return [
            'admin' => $adminApproved,
            'client' => $clientApproved,
            'ready' => $ready,
            'is_client' => $isClient,
        ];
    }

    public function moveItem(int $itemId, string $newStage): void
    {
        $workflow = Workflow::find($itemId);
        if (! $workflow) {
            return;
        }

        $oldStage = $workflow->stage;

        if ($oldStage === $newStage) {
            return;
        }

        // Terminal states: cannot be moved (except ready-for-production → published)
        if ($oldStage === 'published') {
            $this->dispatch('toast', message: 'Published items cannot be moved', type: 'error');

            return;
        }
        if ($oldStage === 'ready-for-production' && $newStage !== 'published') {
            $this->dispatch('toast', message: 'Ready for Production items can only be Published', type: 'error');

            return;
        }

        // Validate approvals required before moving to ready-for-production or published
        if (in_array($newStage, ['ready-for-production', 'published'])) {
            // Only validate approvals if this workflow came from content planner (has content_id)
            // Standalone workflow items (created directly) don't need approval validation
            if ($workflow->content_id) {
                $approval = DB::table('approvals')
                    ->whereNull('deleted_at')
                    ->where('content_id', $workflow->content_id)
                    ->where(function ($q) {
                        $q->where('approval_stage', 'completed')
                            ->orWhere('approval_stage', 'client-pending')
                            ->orWhere('approval_stage', 'admin-pending');
                    })
                    ->first();

                // Check if any approval record exists for this content (even rejected ones)
                $anyApprovalExists = DB::table('approvals')
                    ->whereNull('deleted_at')
                    ->where('content_id', $workflow->content_id)
                    ->exists();

                // If no approval record exists at all, this workflow skipped approval - allow it through
                if (!$anyApprovalExists) {
                    // Workflow was created via "Skip to Workflow" - no approval validation needed
                } else {
                    // Approval record exists, so we need to validate it
                    $isClientContent = ! empty($workflow->client_id);

                    if ($isClientContent) {
                        // Client content: approval must have reached client-pending stage and been approved/completed
                        $isApproved = $approval
                            && in_array($approval->approval_stage, ['completed', 'client-pending'])
                            && in_array($approval->status, ['approved', 'completed']);
                        if (! $isApproved) {
                            $this->dispatch('toast', message: 'Client content requires Admin + Client approval before publishing.', type: 'error');

                            return;
                        }
                    } else {
                        // Internal content: approval must be completed (admin approved, no client stage)
                        $isApproved = $approval
                            && $approval->approval_stage === 'completed'
                            && $approval->status === 'approved';
                        if (! $isApproved) {
                            $this->dispatch('toast', message: 'Internal content requires Admin approval before publishing.', type: 'error');

                            return;
                        }
                    }
                }
            }
            // If no content_id, it's a standalone workflow item — no approval validation needed
        }

        $updateData = ['stage' => $newStage];

        // When moving to review → create Approval #2 (admin-pending)
        if ($newStage === 'review' && $oldStage !== 'review') {
            $existingPending = DB::table('approvals')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($workflow) {
                    if ($workflow->content_id) {
                        $q->where('content_id', $workflow->content_id);
                    } else {
                        $baseTitle = preg_replace('/\s*\([^)]*\)\s*$/', '', $workflow->title ?? '');
                        $q->where('title', $baseTitle)
                            ->orWhere('title', 'like', $baseTitle . '%');
                    }
                })
                ->where('approval_stage', 'admin-pending')
                ->where('status', 'pending')
                ->first();

            if (! $existingPending) {
                // Merge ALL attachments: workflow + content (same as what user sees in workflow detail)
                $allAttachments = [];
                $seenKeys = [];

                $decodeAndAdd = function ($raw) use (&$allAttachments, &$seenKeys) {
                    if (empty($raw)) {
                        return;
                    }
                    if (is_string($raw)) {
                        $raw = json_decode($raw, true);
                    }
                    if (! is_array($raw)) {
                        return;
                    }
                    foreach ($raw as $a) {
                        if (! is_array($a)) {
                            continue;
                        }
                        $key = ($a['id'] ?? null) ? ('id:' . $a['id']) : ('url:' . md5($a['url'] ?? ''));
                        if (! in_array($key, $seenKeys)) {
                            $seenKeys[] = $key;
                            $allAttachments[] = $a;
                        }
                    }
                };

                // 1. Workflow's own attachments
                $decodeAndAdd($workflow->attachments);

                // 2. Content attachments (same as getDetailAttachments shows)
                if (! empty($workflow->content_id)) {
                    $contentAtts = DB::table('contents')->where('id', $workflow->content_id)->value('attachments');
                    $decodeAndAdd($contentAtts);
                }

                DB::table('approvals')->insert([
                    'title' => $workflow->title,
                    'client_id' => $workflow->client_id,
                    'content_id' => $workflow->content_id,
                    'type' => $workflow->type,
                    'status' => 'pending',
                    'approval_stage' => 'admin-pending',
                    'submitted_by' => auth()->id(),
                    'attachments' => ! empty($allAttachments) ? json_encode($allAttachments) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                app(NotificationService::class)->notifyWorkflowReadyForReview($workflow->title);
            }
        }

        // When moving to revision → clear revision notes (will be set via modal)
        if ($newStage === 'revision') {
            $updateData['revision_notes'] = null;
        }

        // When moving to published → mark content as published too
        if ($newStage === 'published' && $workflow->content_id) {
            DB::table('contents')->where('id', $workflow->content_id)->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);
            app(NotificationService::class)->notifyContentPublished($workflow->title, $workflow->client_id);
        }

        if ($oldStage !== $newStage) {
            $maxSort = Workflow::where('stage', $newStage)->max('sort_order') ?? 0;
            $updateData['sort_order'] = $maxSort + 1;
        }

        $workflow->update($updateData);

        // Sync workflow stage changes to content status
        if ($workflow->content_id && ! self::$syncingContent) {
            self::$syncingContent = true;
            $contentStatus = match ($newStage) {
                'todo', 'in-progress' => 'draft',
                'scripting' => 'scripting',
                'review' => 'in-review',
                'revision' => 'revision',
                'ready-for-production' => 'in-review', // Keep as in-review until actually published
                'published' => 'published',
                default => 'draft',
            };
            DB::table('contents')->where('id', $workflow->content_id)->update([
                'status' => $contentStatus,
                'updated_at' => now(),
            ]);
            self::$syncingContent = false;
        }

        app(ActivityLogger::class)->record(
            Auth::user(),
            "Moved workflow '{$workflow->title}' {$oldStage} → {$newStage}"
        );
        app(NotificationService::class)->sendNotification(
            text: "Workflow '{$workflow->title}' moved {$oldStage} → {$newStage}",
            type: 'info',
            link: route('workflow', absolute: false),
            forRole: 'all',
            clientId: $workflow->client_id,
        );

        $this->dispatch('toast', message: $newStage === 'review' ? 'Moved to Review — Approval created in Approvals page' : 'Item moved successfully', type: 'success');
    }

    public function openRevisionModal(int $id): void
    {
        $this->revisionWorkflowId = $id;
        $this->revisionNotes = '';
        $this->showRevisionModal = true;
    }

    public function confirmRevision(): void
    {
        $workflow = Workflow::find($this->revisionWorkflowId);
        if (! $workflow) {
            return;
        }

        if (in_array($workflow->stage, ['published', 'ready-for-production'])) {
            $this->dispatch('toast', message: 'Published and Ready for Production items cannot be revised', type: 'error');

            return;
        }

        $workflow->update([
            'stage' => 'revision',
            'revision_notes' => $this->revisionNotes ?: null,
        ]);

        // Also update content status back to revision
        if ($workflow->content_id) {
            DB::table('contents')->where('id', $workflow->content_id)->update([
                'status' => 'revision',
                'updated_at' => now(),
            ]);
        }

        app(NotificationService::class)->notifyContentRevision($workflow->title, $this->revisionNotes, $workflow->client_id);

        $this->showRevisionModal = false;
        $this->dispatch('toast', message: 'Sent back for revision', type: 'success');
    }

    public function openResubmitModal(int $id): void
    {
        $this->resubmitWorkflowId = $id;
        $this->showResubmitModal = true;
    }

    public function confirmResubmit(): void
    {
        $workflow = Workflow::find($this->resubmitWorkflowId);
        if (! $workflow || $workflow->stage !== 'revision') {
            return;
        }

        // Reset existing approval to pending (reuse, don't duplicate)
        $existingApproval = DB::table('approvals')
            ->whereNull('deleted_at')
            ->where('content_id', $workflow->content_id)
            ->where('approval_stage', 'admin-pending')
            ->whereIn('status', ['rejected', 'revision'])
            ->first();

        if ($existingApproval) {
            DB::table('approvals')->where('id', $existingApproval->id)->update([
                'status' => 'pending',
                'rejection_reason' => null,
                'updated_at' => now(),
            ]);
        } else {
            // Create new approval — merge ALL attachments (workflow + content)
            $allAttachments = [];
            $seenKeys = [];

            $decodeAndAdd = function ($raw) use (&$allAttachments, &$seenKeys) {
                if (empty($raw)) {
                    return;
                }
                if (is_string($raw)) {
                    $raw = json_decode($raw, true);
                }
                if (! is_array($raw)) {
                    return;
                }
                foreach ($raw as $a) {
                    if (! is_array($a)) {
                        continue;
                    }
                    $key = ($a['id'] ?? null) ? ('id:' . $a['id']) : ('url:' . md5($a['url'] ?? ''));
                    if (! in_array($key, $seenKeys)) {
                        $seenKeys[] = $key;
                        $allAttachments[] = $a;
                    }
                }
            };

            $decodeAndAdd($workflow->attachments);

            if (! empty($workflow->content_id)) {
                $contentAtts = DB::table('contents')->where('id', $workflow->content_id)->value('attachments');
                $decodeAndAdd($contentAtts);
            }

            DB::table('approvals')->insert([
                'title' => $workflow->title,
                'client_id' => $workflow->client_id,
                'content_id' => $workflow->content_id,
                'type' => $workflow->type,
                'status' => 'pending',
                'approval_stage' => 'admin-pending',
                'submitted_by' => auth()->id(),
                'attachments' => ! empty($allAttachments) ? json_encode($allAttachments) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $workflow->update(['stage' => 'review']);

        // Update content status back to in-review
        if ($workflow->content_id) {
            DB::table('contents')->where('id', $workflow->content_id)->update([
                'status' => 'in-review',
                'updated_at' => now(),
            ]);
        }

        app(NotificationService::class)->notifyWorkflowReadyForReview($workflow->title);

        $this->showResubmitModal = false;
        $this->dispatch('toast', message: 'Resubmitted for approval', type: 'success');
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
        if (! $workflow) {
            return;
        }

        // Prevent editing of published/ready-for-production items
        if (in_array($workflow->stage, ['published', 'ready-for-production'])) {
            $this->dispatch('toast', message: 'Cannot edit ' . str_replace('-', ' ', $workflow->stage) . ' items. They are locked.', type: 'error');

            return;
        }

        $this->formMode = 'edit';
        $this->editingId = $id;
        $this->formTitle = $workflow->title;
        $this->formDescription = $workflow->notes ?? '';
        $this->formClientId = $workflow->client_id;
        $this->formContentId = $workflow->content_id;
        $this->formType = $workflow->type;
        $this->formPriority = $workflow->priority;
        $this->formAssigneeIds = is_array($workflow->assignee) ? $workflow->assignee : ($workflow->assignee ? [$workflow->assignee] : []);
        $this->formStage = $workflow->stage;
        $this->formDeadline = $workflow->deadline?->format('Y-m-d');
        $rawTags = $workflow->tags;
        if (is_array($rawTags)) {
            $this->formTags = implode(',', $rawTags);
        } elseif (is_string($rawTags) && str_starts_with(trim($rawTags), '[')) {
            $decoded = json_decode($rawTags, true);
            $this->formTags = is_array($decoded) ? implode(',', $decoded) : $rawTags;
        } else {
            $this->formTags = $rawTags ?? '';
        }
        $raw = $workflow->attachments;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $this->formAttachments = is_array($decoded) ? $decoded : [];
        } else {
            $this->formAttachments = is_array($raw) ? $raw : [];
        }
        $this->formAttachmentsJson = json_encode($this->formAttachments);
        $this->showForm = true;
    }

    public function save(): void
    {
        $isBs = NepaliDate::isBs();

        $rules = [
            'formTitle' => 'required|string|max:255',
            'formClientId' => 'nullable|exists:clients,id',
            'formType' => 'required|string|in:reel,post,story,video,carousel,blog,all,content',
            'formPriority' => 'required|string|in:low,medium,high,urgent',
            'formStage' => 'required|string',
            'formAssigneeIds' => 'nullable|array',
        ];

        // Only validate deadline format for AD dates; BS dates are strings
        if (! $isBs) {
            $rules['formDeadline'] = 'nullable|date|after_or_equal:today';
        } else {
            $rules['formDeadline'] = 'nullable|string';
        }

        $this->validate($rules);

        $data = [
            'title' => $this->formTitle,
            'notes' => $this->formDescription,
            'client_id' => $this->formClientId,
            'content_id' => $this->formContentId ?: null,
            'type' => $this->formType,
            'priority' => $this->formPriority,
            'assignee' => ! empty($this->formAssigneeIds) ? $this->formAssigneeIds : null,
            'stage' => $this->formStage,
            'deadline' => $this->formDeadline,
            'tags' => $this->formTags ? trim($this->formTags) : '',
            'attachments' => $this->formAttachments ? array_values($this->formAttachments) : null,
            'submitted_by' => auth()->id(),
        ];

        if ($this->formMode === 'edit' && $this->editingId) {
            $priorAssignee = Workflow::find($this->editingId)?->assignee ?? [];
            if (! is_array($priorAssignee)) {
                $priorAssignee = $priorAssignee ? [$priorAssignee] : [];
            }
            Workflow::findOrFail($this->editingId)->update($data);
            $workflowId = $this->editingId;
            $verb = 'updated';
        } else {
            $priorAssignee = [];
            $maxSort = Workflow::where('stage', $this->formStage)->max('sort_order') ?? 0;
            $data['sort_order'] = $maxSort + 1;

            // Auto-create linked content if not already linked and deadline is set
            // This ensures workflow items appear in content planner
            if (empty($data['content_id']) && ! empty($data['deadline']) && ! self::$syncingContent) {
                self::$syncingContent = true;

                // Map workflow stage to content status
                $contentStatus = match ($data['stage']) {
                    'todo', 'in-progress' => 'draft',
                    'scripting' => 'scripting',
                    'review' => 'in-review',
                    'revision' => 'revision',
                    'ready-for-production', 'published' => 'published',
                    default => 'draft',
                };

                $contentId = DB::table('contents')->insertGetId([
                    'title' => $data['title'],
                    'client_id' => $data['client_id'],
                    'date' => $data['deadline'],
                    'due_date' => null,
                    'platform' => json_encode([$data['type']]), // Use type as platform initially
                    'type' => json_encode([$data['type']]),
                    'status' => $contentStatus,
                    'assignee' => $data['assignee'] ? json_encode($data['assignee']) : null,
                    'caption' => $data['notes'] ?? '',
                    'attachments' => $data['attachments'] ? json_encode($data['attachments']) : null,
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $data['content_id'] = $contentId;
                self::$syncingContent = false;

                app(ActivityLogger::class)->record(Auth::user(), "Auto-created content planner entry for workflow '{$this->formTitle}'");
            }

            $workflowId = Workflow::create($data)->id;
            $verb = 'created';
            // Track package usage (only for client content, not internal)
            if ($this->formClientId) {
                PackageService::recordWorkflow($this->formClientId);
            }
        }

        app(ActivityLogger::class)->record(Auth::user(), "Workflow '{$this->formTitle}' {$verb}");

        // Sync assignees and deadline to linked content
        if (! empty($data['content_id']) && ! self::$syncingContent) {
            self::$syncingContent = true;
            $syncData = [
                'assignee' => $data['assignee'] ? json_encode($data['assignee']) : null,
                'updated_at' => now(),
            ];
            // Only sync date if deadline is set (date column is NOT NULL)
            if (! empty($data['deadline'])) {
                $syncData['date'] = $data['deadline'];
            }
            // Sync workflow stage to content status
            $contentStatus = match ($data['stage']) {
                'todo', 'in-progress' => 'draft',
                'scripting' => 'scripting',
                'review' => 'in-review',
                'revision' => 'revision',
                'ready-for-production', 'published' => 'published',
                default => 'draft',
            };
            $syncData['status'] = $contentStatus;

            DB::table('contents')->where('id', $data['content_id'])->update($syncData);
            self::$syncingContent = false;
        }

        // Notify new assignees (those in new list but not in old)
        $newAssignees = ! empty($this->formAssigneeIds) ? $this->formAssigneeIds : [];
        $actorId = Auth::id();
        $workflowModel = Workflow::find($workflowId);
        if ($workflowModel) {
            foreach ($newAssignees as $uid) {
                if (! in_array($uid, $priorAssignee) && (int) $uid !== $actorId) {
                    $assigneeUser = User::find($uid);
                    if ($assigneeUser) {
                        $assigneeUser->notify(new WorkflowAssignedNotification($workflowModel, Auth::user()));
                    }
                }
            }
        }

        $this->dispatch('toast', message: "Workflow item {$verb}", type: 'success');
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('workflowUpdated');
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::check(), 403);
        $workflow = Workflow::find($id);
        if (! $workflow) {
            return;
        }
        // Only super-admin / admin can delete locked stages (published, ready-for-production).
        // Everyone else needs the UI's stage guard — but wire:call bypasses UI, so re-check here.
        $viewer = Auth::user()->role;
        $isPrivileged = in_array($viewer, ['super-admin', 'admin'], true);
        if (! $isPrivileged && in_array($workflow->stage, ['published', 'ready-for-production'], true)) {
            $this->dispatch('toast', message: 'Only admins can delete locked items', type: 'error');

            return;
        }

        // Soft-delete linked content so it disappears from the content planner
        if ($workflow->content_id) {
            DB::table('contents')->where('id', $workflow->content_id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $workflow->delete();
        app(ActivityLogger::class)->record(Auth::user(), "Deleted workflow #{$id}");
        $this->dispatch('workflowUpdated');
        $this->dispatch('toast', message: 'Workflow item moved to trash', type: 'success');
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
        if ($key === '') {
            $key = 'stage-' . (WorkflowStage::max('id') + 1);
        }
        if (WorkflowStage::where('key', $key)->exists()) {
            $key .= '-' . (WorkflowStage::max('id') + 1);
        }

        WorkflowStage::create([
            'key' => $key,
            'name' => $name,
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
        if (! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return;
        }
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
        if (! $stage) {
            return;
        }
        $old = $stage->name;
        if ($old === $name) {
            return;
        }
        $stage->update(['name' => $name]);
        app(ActivityLogger::class)->record(Auth::user(), "Renamed workflow stage '{$old}' → '{$name}'");
        $this->loadStages();
        $this->dispatch('toast', message: 'Stage renamed', type: 'success');
    }

    public function viewItem(int $id): void
    {
        if (! Workflow::whereKey($id)->exists()) {
            return;
        }
        $this->detailId = $id;
        $this->showDetail = true;
    }

    public function editFromDetail(): void
    {
        if (! $this->detailId) {
            return;
        }
        if (! $this->canEditWorkflow) {
            return;
        }
        $id = $this->detailId;
        $this->showDetail = false;
        $this->edit($id);
    }

    public function getDetailItem()
    {
        if (! $this->detailId) {
            return null;
        }

        return Workflow::with(['client', 'stageInfo'])->find($this->detailId);
    }

    public function getDetailAttachments(): array
    {
        if (! $this->detailId) {
            return [];
        }
        $workflow = Workflow::find($this->detailId);
        if (! $workflow) {
            return [];
        }

        $atts = [];
        $raw = $workflow->attachments;
        if (! is_null($raw)) {
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            if (is_array($raw)) {
                $atts = array_values(array_filter($raw, fn ($a) => is_array($a)));
            }
        }

        if (! empty($workflow->content_id)) {
            $contentAtts = DB::table('contents')->whereNull('deleted_at')->where('id', $workflow->content_id)->value('attachments');
            if (! empty($contentAtts)) {
                if (is_string($contentAtts)) {
                    $contentAtts = json_decode($contentAtts, true);
                }
                if (is_array($contentAtts)) {
                    $validContentAtts = array_values(array_filter($contentAtts, fn ($a) => is_array($a)));
                    $atts = array_merge($atts, $validContentAtts);
                }
            }
        }

        $atts = array_map(function ($a) {
            if (empty($a['url']) && ! empty($a['id'])) {
                $file = DB::table('files')->where('id', $a['id'])->first();
                if ($file && $file->path) {
                    $a['url'] = Storage::url($file->path);
                }
            }

            return $a;
        }, $atts);

        return array_values(array_filter($atts, fn ($a) => ! empty($a['url'])));
    }

    public function getComments()
    {
        if (! $this->detailId) {
            return collect();
        }

        return Comment::with('user')
            ->where('commentable_type', Workflow::class)
            ->where('commentable_id', $this->detailId)
            ->latest()
            ->get();
    }

    public function getApprovalAttachments(): array
    {
        $workflow = Workflow::find($this->detailId);
        if (! $workflow) {
            return [];
        }

        $approvals = DB::table('approvals')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($workflow) {
                if ($workflow->content_id) {
                    $q->where('content_id', $workflow->content_id);
                } else {
                    $baseTitle = preg_replace('/\s*\([^)]*\)\s*$/', '', $workflow->title ?? '');
                    if ($baseTitle) {
                        $q->where('title', $baseTitle)
                            ->orWhere('title', 'like', $baseTitle . '%');
                    }
                }
            })
            ->whereNotNull('attachments')
            ->where('attachments', '!=', 'null')
            ->where('attachments', '!=', '[]')
            ->get();

        $allAttachments = [];
        foreach ($approvals as $approval) {
            $atts = json_decode($approval->attachments, true) ?? [];
            foreach ($atts as $att) {
                $att['_approval_id'] = $approval->id;
                $att['_approval_status'] = $approval->status;
                $att['_approval_stage'] = $approval->approval_stage;
                $allAttachments[] = $att;
            }
        }

        return $allAttachments;
    }

    public function addComment(string $attachmentsJson = '[]'): void
    {
        $hasText = trim($this->commentText) !== '';
        $attachments = json_decode($attachmentsJson, true) ?: [];
        $hasFiles = ! empty($attachments);

        if (! $hasText && ! $hasFiles) {
            return;
        }

        if (! $this->detailId) {
            return;
        }

        $workflow = Workflow::find($this->detailId);
        if (! $workflow) {
            return;
        }

        if ($hasFiles && ! $hasText) {
            $existingRaw = DB::table('workflows')->whereNull('deleted_at')->where('id', $this->detailId)->value('attachments');
            $existing = $existingRaw ? (json_decode($existingRaw, true) ?: []) : [];
            $merged = array_values(array_merge($existing, $attachments));

            DB::table('workflows')->where('id', $this->detailId)->update([
                'attachments' => json_encode($merged),
                'updated_at' => now(),
            ]);

            // Notify assigned user + admins (not self)
            $this->notifyRecipients(
                $workflow,
                new WorkflowAttachedNotification($workflow, $attachments, Auth::user()),
                'workflow'
            );

            $this->commentAttachments = '[]';
            $this->dispatch('workflowUpdated');
            $this->dispatch('toast', message: 'Files attached', type: 'success');

            return;
        }

        $comment = Comment::create([
            'commentable_type' => Workflow::class,
            'commentable_id' => $this->detailId,
            'user_id' => Auth::id(),
            'body' => $this->commentText,
            'attachments' => $attachments ?: null,
        ]);

        app(ActivityLogger::class)->record(
            Auth::user(),
            "Commented on workflow #{$this->detailId}"
        );

        // Notify assigned user + admins (not self)
        $this->notifyRecipients(
            $workflow,
            new WorkflowCommentNotification($comment, $workflow, Auth::user()),
            'workflow'
        );

        $this->commentText = '';
        $this->commentAttachments = '[]';
        $this->dispatch('tiptap-set-content', name: 'wfComment', html: '');
        $this->dispatch('toast', message: 'Comment added', type: 'success');
    }

    /**
     * Send a notification to all relevant recipients except the actor.
     */
    private function notifyRecipients($entity, $notification, string $type): void
    {
        $actorId = Auth::id();
        $recipientIds = collect();

        // Add assigned user
        $assigneeIds = $entity->assignee ?? [];
        if (is_array($assigneeIds)) {
            $recipientIds = $recipientIds->merge($assigneeIds);
        } elseif ($assigneeIds) {
            $recipientIds->push($assigneeIds);
        }

        // Add admins/managers (NOT super-admin — they control the system)
        $adminIds = User::whereIn('role', ['admin', 'manager'])
            ->where('status', 'active')
            ->pluck('id');
        $recipientIds = $recipientIds->merge($adminIds);

        // Remove self
        $recipientIds = $recipientIds->filter(fn ($id) => (int) $id !== (int) $actorId)->unique();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $recipients = User::whereIn('id', $recipientIds)->where('status', 'active')->get();
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    public function getPickableFiles(?string $search = null, ?int $clientId = null, ?int $folderId = null): array
    {
        $q = File::select('id', 'name', 'type', 'size')
            ->orderBy('name');

        if ($folderId) {
            $q->where('folder_id', $folderId);
        } elseif ($folderId === 0) {
            $q->whereNull('folder_id');
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

    public function getPickableFolders(int $parentId = 0, ?int $clientId = null): array
    {
        $q = Folder::select('id', 'name')
            ->orderBy('name');

        if ($parentId > 0) {
            $q->where('parent_id', $parentId);
        } else {
            $q->whereNull('parent_id');
        }

        $folders = $q->get()->map(function ($folder) {
            $fileCount = File::where('folder_id', $folder->id)->count();

            return [
                'id' => $folder->id,
                'name' => $folder->name,
                'file_count' => $fileCount,
            ];
        })->toArray();

        $breadcrumbs = [];
        $current = $parentId;
        while ($current > 0) {
            $folder = Folder::select('id', 'name', 'parent_id')->find($current);
            if ($folder) {
                array_unshift($breadcrumbs, ['id' => $folder->id, 'name' => $folder->name]);
                $current = $folder->parent_id ?? 0;
            } else {
                break;
            }
        }

        return ['folders' => $folders, 'breadcrumbs' => $breadcrumbs];
    }

    public function updatedNewFileUpload(): void
    {
        if (! $this->newFileUpload) {
            return;
        }

        $file = $this->newFileUpload;
        $path = $file->store('files/' . now()->format('Y/m'), 'public');

        $ext = strtolower($file->getClientOriginalExtension());
        $typeMap = ['jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image', 'svg' => 'image',
            'mp4' => 'video', 'mov' => 'video', 'avi' => 'video', 'webm' => 'video', 'mkv' => 'video',
            'mp3' => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'aac' => 'audio', 'm4a' => 'audio'];
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

    public function reorderStages(array $stageIds): void
    {
        DB::transaction(function () use ($stageIds) {
            foreach ($stageIds as $index => $stageId) {
                WorkflowStage::where('id', $stageId)->update(['order' => $index]);
            }
        });
        $this->loadStages();
    }

    public function reorderCards(array $cardIds): void
    {
        if (empty($cardIds)) {
            return;
        }

        DB::transaction(function () use ($cardIds) {
            foreach ($cardIds as $index => $cardId) {
                // Use Eloquent model to respect global scopes (client account isolation)
                $workflow = Workflow::find($cardId);
                if ($workflow) {
                    $workflow->sort_order = $index;
                    $workflow->timestamps = false; // Avoid unnecessary timestamp updates
                    $workflow->save();
                }
            }
        });
    }

    public function reorderMultipleLanes(array $lanes): void
    {
        // Batch update for multiple lanes to avoid race conditions
        // Expected format: [['stage' => 'draft', 'cardIds' => [1,2,3]], ...]
        DB::transaction(function () use ($lanes) {
            foreach ($lanes as $lane) {
                if (empty($lane['cardIds'])) {
                    continue;
                }

                foreach ($lane['cardIds'] as $index => $cardId) {
                    $workflow = Workflow::find($cardId);
                    if ($workflow) {
                        $workflow->sort_order = $index;
                        $workflow->timestamps = false;
                        $workflow->save();
                    }
                }
            }
        });
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
        $this->formAssigneeIds = [];
        $this->formDeadline = null;
        $this->formTags = '';
        $this->formAttachments = [];
        $this->formAttachmentsJson = '[]';
        if (! empty($this->stages)) {
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
        return UserVisibility::apply(User::query())
            ->with('department')
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                'department' => $u->department?->name ?? '',
                'avatar' => $u->avatar,
            ])
            ->toArray();
    }

    #[On('create-task-from-workflow')]
    public function createTaskFromWorkflow(int $workflowId, string $title = '', ?int $clientId = null): void
    {
        $this->dispatch('navigate', url: route('tasks') . '?workflow_id=' . $workflowId . '&workflow_title=' . urlencode($title) . ($clientId ? '&client_id=' . $clientId : ''));
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
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
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
            <x-search-input wire="search" placeholder="Search workflows..." />
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
            justDragged: false,
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
                    data-stage="{{ $stage['key'] }}"
                >
                    @forelse ($stageItems as $item)
                        @php
                            $isOverdue = $item->deadline && $item->deadline->isPast() && !in_array($item->stage, ['published', 'ready-for-production']);
                        @endphp
                        <div
                            class="kanban-card {{ $isOverdue ? 'overdue' : '' }} bg-white rounded-xl border border-gray-100 p-3 {{ $this->canMoveWorkflow ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer' }} hover:shadow-md hover:border-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]/40 focus-visible:border-[var(--brand)] transition-all duration-150"
                            data-id="{{ $item->id }}"
                            @if ($this->canMoveWorkflow) data-draggable="true" @endif
                            role="button"
                            tabindex="0"
                            aria-label="View workflow item: {{ $item->title }}"
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
                                    $isLocked = in_array($item->stage, ['published', 'ready-for-production']);
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
                                @if ($isLocked)
                                    <span
                                        class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold bg-gray-100 text-gray-600"
                                        title="Locked - Cannot edit"
                                    >
                                        <i class="fas fa-lock text-[9px]"></i>
                                    </span>
                                @endif
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

                            {{-- Linked Content Info --}}
                            @if ($item->content)
                                <div class="text-[11px] text-gray-500 mb-2 flex items-center gap-2">
                                    @if ($item->content->date)
                                        <span class="flex items-center gap-1">
                                            <i class="fas fa-calendar text-gray-400"></i>
                                            {{ \App\Support\NepaliDate::displayShort($item->content->date) }}
                                        </span>
                                    @endif
                                    @if ($item->content->platform)
                                        @php
                                            $platforms = is_string($item->content->platform) ? json_decode($item->content->platform, true) : $item->content->platform;
                                        @endphp
                                        @if (is_array($platforms) && count($platforms) > 0)
                                            <span class="flex items-center gap-1">
                                                <i class="fas fa-share-alt text-gray-400"></i>
                                                {{ implode(', ', array_slice($platforms, 0, 2)) }}{{ count($platforms) > 2 ? ' +' . (count($platforms) - 2) : '' }}
                                            </span>
                                        @endif
                                    @endif
                                </div>
                            @endif

                            {{-- Attachment indicator --}}
                            @php
                                $hasAttachments = false;
                                if ($item->attachments) {
                                    $atts = is_string($item->attachments) ? json_decode($item->attachments, true) : $item->attachments;
                                    $hasAttachments = is_array($atts) && count($atts) > 0;
                                }
                            @endphp
                            @if ($hasAttachments)
                                <div class="text-[11px] text-gray-500 mb-2 flex items-center gap-1">
                                    <i class="fas fa-paperclip text-gray-400"></i>
                                    <span>{{ count($atts) }} file{{ count($atts) > 1 ? 's' : '' }}</span>
                                </div>
                            @endif

                            {{-- Bottom: Assignees + Deadline --}}
                            <div class="flex items-center justify-between mt-2">
                                @php $assignees = $item->getAssigneeUsers(); @endphp
                                @if ($assignees->isNotEmpty())
                                    <div class="flex items-center -space-x-1.5">
                                        @foreach ($assignees->take(3) as $au)
                                            <div
                                                class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-[9px] font-bold text-[var(--brand)] ring-2 ring-white"
                                                title="{{ $au->name }}"
                                            >
                                                {{ $au->initials }}
                                            </div>
                                        @endforeach
                                        @if ($assignees->count() > 3)
                                            <span class="text-[10px] text-gray-400 ml-1"
                                                >+{{ $assignees->count() - 3 }}</span
                                            >
                                        @endif
                                    </div>
                                @else
                                    <span class="text-[11px] text-gray-400">Unassigned</span>
                                @endif

                                <div class="flex items-center gap-1.5">
                                    @if ($item->deadline)
                                        <span
                                            class="inline-flex items-center gap-1 text-[11px] {{ $isOverdue ? 'text-red-600 font-semibold' : 'text-gray-500' }}"
                                        >
                                            <i
                                                class="fas fa-calendar-alt text-[10px] {{ $isOverdue ? 'text-red-500' : 'text-gray-400' }}"
                                            ></i>
                                            {{ \App\Support\NepaliDate::displayShort($item->deadline) }}
                                        </span>
                                    @endif
                                    <button
                                        x-on:click.stop="$dispatch('create-task-from-workflow', { workflowId: {{ $item->id }}, title: {{ \Illuminate\Support\Js::from($item->title) }}, clientId: {{ $item->client_id ?? 'null' }} })"
                                        class="w-6 h-6 flex items-center justify-center rounded-md text-gray-400 hover:bg-amber-50 hover:text-amber-600 transition"
                                        title="Create task for this item"
                                    >
                                        <i class="fas fa-plus text-[10px]"></i>
                                    </button>
                                </div>
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
                    'urgent' => 'bg-red-100 text-red-700 border-red-200',
                    'high'   => 'bg-orange-100 text-orange-700 border-orange-200',
                    'medium' => 'bg-blue-100 text-blue-700 border-blue-200',
                    default  => 'bg-gray-100 text-gray-600 border-gray-200',
                };
                $typeBadge = match($detail->type) {
                    'reel'     => 'bg-pink-50 text-pink-700 border-pink-200',
                    'post'     => 'bg-blue-50 text-blue-700 border-blue-200',
                    'story'    => 'bg-purple-50 text-purple-700 border-purple-200',
                    'video'    => 'bg-red-50 text-red-700 border-red-200',
                    'carousel' => 'bg-amber-50 text-amber-700 border-amber-200',
                    'blog'     => 'bg-green-50 text-green-700 border-green-200',
                    default    => 'bg-gray-50 text-gray-600 border-gray-200',
                };
                $isOverdueDetail = $detail->deadline && $detail->deadline->isPast() && !in_array($detail->stage, ['published', 'ready-for-production']);
            @endphp
            <div
                wire:transition.opacity.duration.150ms
                class="modal-overlay"
                x-data
                x-on:keydown.escape.window="$wire.set('showDetail', false)"
            >
                <div class="modal-box max-w-4xl w-full max-h-[90vh] flex flex-col" x-on:click.stop>
                    {{-- Header --}}
                    <div class="modal-header border-b border-gray-100 pb-3 shrink-0">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5 mb-2">
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $typeBadge }}"
                                    >{{ ucfirst($detail->type) }}</span
                                >
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $priorityBadge }}"
                                    >{{ ucfirst($detail->priority) }}</span
                                >
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 border border-gray-100 px-2.5 py-1 text-[11px] font-semibold text-gray-700"
                                >
                                    <span
                                        class="h-2 w-2 rounded-full"
                                        style="background-color: {{ $detail->stageInfo->color ?? '#6b7280' }}"
                                    ></span>
                                    {{ $detail->stageInfo->name ?? ucfirst($detail->stage) }}
                                </span>
                                @if (in_array($detail->stage, ['published', 'ready-for-production']))
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-gray-100 border border-gray-200 px-2.5 py-1 text-[11px] font-semibold text-gray-600"
                                        title="Locked - Cannot edit"
                                    >
                                        <i class="fas fa-lock text-[10px]"></i>
                                        Locked
                                    </span>
                                @endif
                            </div>
                            <h3 class="text-base md:text-lg font-bold text-gray-900 leading-snug pr-2">
                                {{ $detail->title }}
                            </h3>
                        </div>
                        <div class="flex items-start gap-2 shrink-0 ml-2">
                            @if ($this->canEditWorkflow && !in_array($detail->stage, ['published', 'ready-for-production']))
                                <button
                                    type="button"
                                    wire:click="editFromDetail"
                                    class="btn btn-secondary btn-sm whitespace-nowrap"
                                >
                                    <i class="fas fa-pen text-xs"></i> <span class="hidden sm:inline">Edit</span>
                                </button>
                            @endif
                            @if (in_array(Auth::user()->role, ['super-admin', 'admin'], true))
                                <button
                                    type="button"
                                    wire:click="$dispatch('open-confirm', { title: 'Delete Workflow Item?', message: 'This item and its activity will be moved to trash.', type: 'danger', action: 'delete', params: [{{ $detail->id }}] })"
                                    class="btn btn-sm bg-red-50 text-red-600 border border-red-200 hover:bg-red-100 whitespace-nowrap"
                                    aria-label="Delete workflow item"
                                >
                                    <i class="fas fa-trash text-xs"></i> <span class="hidden sm:inline">Delete</span>
                                </button>
                            @endif
                            <button
                                @click="$wire.set('showDetail', false)"
                                type="button"
                                class="btn btn-ghost btn-icon btn-sm"
                                aria-label="Close"
                            >
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <div class="modal-body flex-1 overflow-y-auto space-y-4 p-4 md:p-6">
                        {{-- Description --}}
                        @if ($detail->notes)
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Description</p>
                                <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $detail->notes }}</p>
                            </div>
                        @endif

                        {{-- Metadata --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Client</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-building text-gray-400"></i> {{ $detail->client->name ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Assignee</p>
                                @php $detailAssignees = $detail->getAssigneeUsers(); @endphp
                                @if ($detailAssignees->isNotEmpty())
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($detailAssignees as $au)
                                            <div
                                                class="inline-flex items-center gap-1.5 bg-[rgba(var(--brand-rgb),0.08)] rounded-full px-2 py-1 border border-[rgba(var(--brand-rgb),0.15)]"
                                            >
                                                <div
                                                    class="flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.2)] text-[8px] font-bold text-[var(--brand)]"
                                                >
                                                    {{ $au->initials }}
                                                </div>
                                                <span
                                                    class="text-[var(--brand)] text-xs font-medium"
                                                    >{{ $au->name }}</span
                                                >
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-gray-400 italic text-xs">Unassigned</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Deadline</p>
                                @if ($detail->deadline)
                                    <p
                                        class="{{ $isOverdueDetail ? 'text-red-600 font-semibold' : 'text-gray-800' }} text-xs flex items-center gap-1.5"
                                    >
                                        <i
                                            class="fas fa-calendar-alt {{ $isOverdueDetail ? 'text-red-500' : 'text-gray-400' }}"
                                        ></i>
                                        {{ \App\Support\NepaliDate::display($detail->deadline) }}
                                        @if ($isOverdueDetail)
                                            <span
                                                class="inline-flex items-center rounded-md bg-red-100 text-red-700 px-1.5 py-0.5 text-[10px] font-semibold"
                                                >Overdue</span
                                            >
                                        @endif
                                    </p>
                                @else
                                    <p class="text-gray-400 italic text-xs">No deadline</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Created</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-clock text-gray-400"></i> {{ $detail->created_at ? \App\Support\NepaliDate::display($detail->created_at) : '—' }}</p>
                            </div>
                            @if ($detail->content_id)
                                @php $linkedContent = \App\Models\Content::find($detail->content_id); @endphp
                                @if ($linkedContent)
                                    <div>
                                        <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Linked Content</p>
                                        <div class="text-gray-800 text-xs space-y-1">
                                            <p class="flex items-center gap-1.5"><i class="fas fa-link text-gray-400"></i> {{ $linkedContent->title }}</p>
                                            @if ($linkedContent->date)
                                                <p class="flex items-center gap-1.5"><i class="fas fa-calendar text-gray-400"></i> Posting: {{ \App\Support\NepaliDate::display($linkedContent->date) }}</p>
                                            @endif
                                            @if ($linkedContent->platform)
                                                @php
                                                    $platforms = is_string($linkedContent->platform) ? json_decode($linkedContent->platform, true) : $linkedContent->platform;
                                                @endphp
                                                @if (is_array($platforms) && count($platforms) > 0)
                                                    <p class="flex items-center gap-1.5"><i class="fas fa-share-alt text-gray-400"></i> {{ implode(', ', $platforms) }}</p>
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <div>
                                        <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Linked Content</p>
                                        <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-link text-gray-400"></i> Content #{{ $detail->content_id }}</p>
                                    </div>
                                @endif
                            @endif
                        </div>

                        {{-- Tags --}}
                        @if ($detail->tags && trim($detail->tags) !== '')
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1.5">Tags</p>
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

                        {{-- Attachments --}}
                        @php $wfAtts = $this->getDetailAttachments(); @endphp
                        @if (count($wfAtts) > 0)
                            <div>
                                @include ('livewire.partials.attachment-display', ['attachments' => $wfAtts, 'label' => 'Attachments'])
                            </div>
                        @endif

                        {{-- Approval Attachments --}}
                        @php $approvalAtts = $this->getApprovalAttachments() ?? []; @endphp
                        @if (!empty($approvalAtts))
                            <div>
                                @include ('livewire.partials.attachment-display', ['attachments' => $approvalAtts, 'label' => 'Approval Attachments'])
                            </div>
                        @endif

                        {{-- Discussion --}}
                        <div class="border-t border-gray-100 pt-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fas fa-comments text-gray-400"></i> Discussion
                            </h4>

                            @php $comments = $this->getComments(); @endphp
                            <div class="space-y-3 max-h-60 overflow-y-auto mb-3 pr-2">
                                @forelse ($comments as $comment)
                                    <div class="flex gap-2.5">
                                        <div
                                            class="flex-shrink-0 w-6 h-6 rounded-full bg-[rgba(var(--brand-rgb),0.1)] flex items-center justify-center text-[9px] font-bold text-[var(--brand)]"
                                        >
                                            {{ strtoupper(substr($comment->user->name ?? '?', 0, 1)) }}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-0.5">
                                                <span
                                                    class="text-xs font-semibold text-gray-800"
                                                    >{{ $comment->user->name ?? 'Unknown' }}</span
                                                >
                                                <span
                                                    class="text-[10px] text-gray-400"
                                                    >{{ $comment->created_at->diffForHumans() }}</span
                                                >
                                            </div>
                                            <div class="comment-body text-sm text-gray-600">{!! $comment->body !!}</div>
                                            @if ($comment->attachments)
                                                <div class="flex flex-wrap gap-1 mt-1">
                                                    @foreach ($comment->attachments as $att)
                                                        @if (($att['type'] ?? '') === 'image')
                                                            <a href="{{ $att['url'] }}" target="_blank" class="block"
                                                                ><img
                                                                    src="{{ $att['url'] }}"
                                                                    class="rounded-lg max-h-20 border border-gray-100"
                                                            /></a>
                                                        @else
                                                            <a
                                                                href="{{ $att['url'] }}"
                                                                target="_blank"
                                                                class="inline-flex items-center gap-1 bg-gray-100 rounded-lg px-2 py-1 text-xs text-gray-600 hover:bg-gray-200"
                                                            >
                                                                <i
                                                                    class="fas {{ ($att['type'] ?? '') === 'drive' ? 'fa-google-drive text-blue-500' : 'fa-file text-gray-400' }}"
                                                                ></i>
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
                                <x-tiptap-editor wire="commentText" name="wfComment" placeholder="Add a comment..." />
                                <div class="flex items-center justify-between mt-2">
                                    <x-file-picker
                                        :clientId="$detail->client_id"
                                        wire="commentAttachments"
                                        :initial="$commentAttachments"
                                    />
                                    <button
                                        @click="$wire.addComment($wire.get('commentAttachments'))"
                                        wire:loading.attr="disabled"
                                        class="btn btn-primary btn-sm"
                                        x-data
                                    >
                                        <i
                                            class="fas fa-paper-plane text-xs"
                                            wire:loading.remove
                                            wire:target="addComment"
                                        ></i>
                                        <i class="fas fa-spinner fa-spin text-xs" wire:loading></i>
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

    {{-- ========== WORKFLOW FORM MODAL ========== --}}
    @if ($showForm)
        <div class="modal-overlay" x-data x-on:keydown.escape.window="$wire.set('showForm', false)">
            <div class="modal-box max-w-lg" x-on:click.stop>
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900">
                        <i class="fas fa-{{ $formMode === 'edit' ? 'pen' : 'plus' }} text-[var(--brand)] mr-2"></i>
                        {{ $formMode === 'edit' ? 'Edit Workflow Item' : 'Add Workflow Item' }}
                    </h3>
                    <button
                        @click="$wire.set('showForm', false)"
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <div class="modal-body">
                    <form wire:submit="save" class="flex flex-col max-h-[75vh]">
                        <div class="flex-1 overflow-y-auto space-y-4 pr-1 -mr-1">
                            {{-- Title --}}
                            <div>
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
                            </div>

                            {{-- Description --}}
                            <div>
                                <label class="form-label">Description</label>
                                <textarea
                                    wire:model="formDescription"
                                    class="form-textarea w-full"
                                    rows="4"
                                    placeholder="Brief description or notes..."
                                    x-data
                                    x-init="
                                        $nextTick(() => { 
                                            $el.style.height = 'auto'; 
                                            $el.style.height = ($el.scrollHeight + 2) + 'px'; 
                                        });
                                        $watch('$wire.formDescription', value => {
                                            $el.style.height = 'auto';
                                            $el.style.height = ($el.scrollHeight + 2) + 'px';
                                        });
                                    "
                                    x-on:input="
                                        $el.style.height = 'auto';
                                        $el.style.height = ($el.scrollHeight + 2) + 'px';
                                    "
                                    style="min-height: 100px; resize: vertical;"
                                ></textarea>
                            </div>

                            {{-- Attachments --}}
                            <div class="border border-dashed border-gray-200 rounded-xl bg-gray-50/50 p-3">
                                <label class="form-label mb-2 text-gray-500"
                                    ><i class="fas fa-paperclip text-gray-400 mr-1"></i> Attachments</label
                                >
                                <x-file-picker
                                    :clientId="$formClientId"
                                    wire="formAttachmentsJson"
                                    :initial="$formAttachments"
                                    wireClientId="formClientId"
                                />
                                @if ($formMode === 'edit' && count($formAttachments) > 0)
                                    <div class="mt-2">
                                        @include ('livewire.partials.attachment-display', ['attachments' => $formAttachments, 'label' => ''])
                                    </div>
                                @endif
                            </div>

                            {{-- Row: Client + Type --}}
                            <div class="grid grid-cols-2 gap-3">
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
                                <div>
                                    <label class="form-label">Type <span class="text-red-500">*</span></label>
                                    <select wire:model="formType" class="form-select">
                                        <option value="reel">Reel</option>
                                        <option value="post">Post</option>
                                        <option value="story">Story</option>
                                        <option value="video">Video</option>
                                        <option value="carousel">Carousel</option>
                                        <option value="blog">Blog</option>
                                        <option value="all">All</option>
                                        <option value="content">Content</option>
                                    </select>
                                    @error ('formType')
                                        <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            {{-- Row: Priority + Stage --}}
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Priority <span class="text-red-500">*</span></label>
                                    <select wire:model="formPriority" class="form-select">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                    @error ('formPriority')
                                        <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                    @enderror
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
                            </div>

                            {{-- Link to Content (conditional) --}}
                            @if ($formClientId)
                                <div x-data>
                                    <label class="form-label"
                                        >Link to Content <span class="text-gray-400 text-xs">(optional)</span></label
                                    >
                                    <select wire:model="formContentId" class="form-select">
                                        <option value="">No linked content</option>
                                        @foreach ($this->getAvailableContent() as $content)
                                            @php
                                                $contentPlatforms = is_string($content->platform) ? json_decode($content->platform, true) : $content->platform;
                                                $platformLabel = is_array($contentPlatforms) ? implode(', ', array_map(fn($p) => ucfirst($p), $contentPlatforms)) : ucfirst($content->platform ?? '');
                                            @endphp
                                            <option value="{{ $content->id }}">
                                                {{ $content->title }} — {{ \App\Support\NepaliDate::displayShort($content->date) }} ({{ $platformLabel }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            {{-- Assignees --}}
                            <div>
                                <label class="form-label">Assignees</label>
                                @include ('livewire.partials.assignee-select', ['usersJson' => json_encode($this->getUserList()), 'livewireProp' => 'formAssigneeIds'])
                                @error ('formAssigneeIds')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Row: Deadline + Tags --}}
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Deadline</label>
                                    <x-date-input model="formDeadline" name="formDeadline" />
                                </div>
                                <div>
                                    <label class="form-label">Tags</label>
                                    <input
                                        type="text"
                                        wire:model="formTags"
                                        class="form-input"
                                        placeholder="urgent, design, revision"
                                    />
                                    <p class="text-[10px] text-gray-400 mt-0.5">Comma separated</p>
                                </div>
                            </div>
                        </div>

                        {{-- Actions (fixed at bottom) --}}
                        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4 mt-4 shrink-0">
                            <button type="button" @click="$wire.set('showForm', false)" class="btn btn-secondary">
                                Cancel
                            </button>
                            @if ($formMode === 'edit' && !in_array($formStage, ['published', 'ready-for-production']))
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
            <div class="modal-box max-w-lg" x-on:click.stop>
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900">
                        <i class="fas fa-cog text-[var(--brand)] mr-2"></i>
                        Manage Workflow Stages
                    </h3>
                    <button
                        @click="$wire.set('showStageManager', false)"
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <div class="modal-body space-y-4">
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
                                    class="flex h-7 w-7 items-center justify-center rounded-lg text-gray-300 hover:text-red-500 hover:bg-red-50 transition-colors sm:opacity-0 sm:group-hover:opacity-100"
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

    {{-- ─── REVISION MODAL ────────────────────────────── --}}
    @if ($showRevisionModal)
        <div
            class="modal-overlay z-50"
            wire:click.self="$set('showRevisionModal', false)"
            x-on:keydown.escape.window="$wire.set('showRevisionModal', false)"
        >
            <div class="modal-box max-w-md">
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900">
                        <i class="fas fa-undo text-red-500 mr-2"></i>Send Back for Revision
                    </h3>
                    <button
                        @click="$wire.set('showRevisionModal', false)"
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="modal-body space-y-4">
                    <p class="text-sm text-gray-600">This item will be moved to <strong>Revision</strong> stage. The staff will be notified.</p>
                    <div>
                        <label class="form-label">Revision Notes <span class="text-red-500">*</span></label>
                        <textarea
                            wire:model="revisionNotes"
                            class="form-input"
                            rows="3"
                            placeholder="What needs to be changed?"
                        ></textarea>
                    </div>
                    <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                        <button @click="$wire.set('showRevisionModal', false)" type="button" class="btn btn-secondary">
                            Cancel
                        </button>
                        <button
                            wire:click="confirmRevision"
                            class="btn btn-danger"
                            wire:loading.attr="disabled"
                            wire:target="confirmRevision"
                        >
                            <span wire:loading.remove wire:target="confirmRevision"
                                ><i class="fas fa-undo mr-1"></i> Send for Revision</span
                            >
                            <span wire:loading wire:target="confirmRevision" class="flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ─── RESUBMIT MODAL ────────────────────────────── --}}
    @if ($showResubmitModal)
        <div
            class="modal-overlay z-50"
            wire:click.self="$set('showResubmitModal', false)"
            x-on:keydown.escape.window="$wire.set('showResubmitModal', false)"
        >
            <div class="modal-box max-w-md">
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900">
                        <i class="fas fa-paper-plane text-[var(--brand)] mr-2"></i>Resubmit for Approval
                    </h3>
                    <button
                        @click="$wire.set('showResubmitModal', false)"
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="modal-body space-y-4">
                    <p class="text-sm text-gray-600">This item will be moved to <strong>Review</strong> stage and sent for admin approval again.</p>
                    <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                        <button @click="$wire.set('showResubmitModal', false)" type="button" class="btn btn-secondary">
                            Cancel
                        </button>
                        <button
                            wire:click="confirmResubmit"
                            class="btn btn-primary"
                            wire:loading.attr="disabled"
                            wire:target="confirmResubmit"
                        >
                            <span wire:loading.remove wire:target="confirmResubmit"
                                ><i class="fas fa-paper-plane mr-1"></i> Resubmit</span
                            >
                            <span wire:loading wire:target="confirmResubmit" class="flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @script
        <script>
            window.initWorkflowSortable = function () {
                if (typeof Sortable === 'undefined') return;
                document.querySelectorAll('.cards-area').forEach(function (el) {
                    if (el._sortable) {
                        el._sortable.destroy();
                    }
                    el._sortable = new Sortable(el, {
                        group: 'workflow-board',
                        animation: 150,
                        ghostClass: 'opacity-40',
                        chosenClass: 'drag-chosen',
                        dragClass: 'drag-active',
                        draggable: '.kanban-card[data-draggable]',
                        onEnd: function (evt) {
                            var boardEl = evt.from.closest('.kanban-board');
                            if (boardEl && boardEl.__x) {
                                boardEl.__x.$data.justDragged = true;
                                setTimeout(function () {
                                    boardEl.__x.$data.justDragged = false;
                                }, 200);
                            }
                            var cardId = parseInt(evt.item.dataset.id);
                            var fromStage = evt.from.dataset.stage;
                            var toStage = evt.to.dataset.stage;
                            var toCardIds = Array.from(evt.to.children)
                                .filter(function (c) {
                                    return c.dataset && c.dataset.id;
                                })
                                .map(function (c) {
                                    return parseInt(c.dataset.id);
                                });
                            var wireEl = evt.from.closest('[wire\\:id]');
                            if (!wireEl) return;
                            var wireId = wireEl.getAttribute('wire:id');
                            if (!wireId) return;
                            var component = Livewire.find(wireId);
                            if (!component) return;

                            // Batch updates to avoid race conditions
                            if (fromStage !== toStage) {
                                // Moving between stages
                                var fromCardIds = Array.from(evt.from.children)
                                    .filter(function (c) {
                                        return c.dataset && c.dataset.id;
                                    })
                                    .map(function (c) {
                                        return parseInt(c.dataset.id);
                                    });

                                // First move the item to the new stage
                                component.call('moveItem', cardId, toStage).then(function () {
                                    // Then update both lanes in a single transaction
                                    component.call('reorderMultipleLanes', [
                                        { stage: fromStage, cardIds: fromCardIds },
                                        { stage: toStage, cardIds: toCardIds }
                                    ]);
                                });
                            } else {
                                // Same stage reordering
                                component.call('reorderCards', toCardIds);
                            }
                        }
                    });
                });
            };

            // Initialize immediately when the component loads/mounts
            setTimeout(window.initWorkflowSortable, 100);

            // Hook into Livewire's morph lifecycle for reactivity
            if (!window._wfSortableInit) {
                window._wfSortableInit = true;
                Livewire.hook('morphed', () => {
                    setTimeout(window.initWorkflowSortable, 50);
                });
            }
        </script>
    @endscript
</div>
