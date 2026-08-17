<?php

use Anuzpandey\LaravelNepaliDate\Exceptions\InvalidDateException;
use Anuzpandey\LaravelNepaliDate\LaravelNepaliDate;
use App\Models\ClientAccount;
use App\Models\Comment;
use App\Models\Content;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Models\Workflow;
use App\Notifications\ContentAssignedNotification;
use App\Notifications\ContentAttachedNotification;
use App\Notifications\ContentCommentNotification;
use App\Notifications\WorkflowAssignedNotification;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\PackageService;
use App\Support\ContentTags;
use App\Support\NepaliDate;
use App\Support\UserVisibility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    protected static bool $syncingWorkflow = false;

    public string $viewMode = 'month';

    public int $currentMonth;

    public int $currentYear;

    public string $search = '';

    public string $platformFilter = '';

    public string $statusFilter = '';

    public string $clientFilter = '';

    public bool $showForm = false;

    public bool $showDayDetail = false;

    public ?string $selectedDate = null;

    public int $editingId = 0;

    public string $title = '';

    public ?int $formClientId = null;

    public string $formDate = '';

    public string $formDueDate = '';

    public array $formPlatforms = [];

    public array $formTypes = [];

    public string $formStatus = 'draft';

    public array $formAssigneeIds = [];

    public string $caption = '';

    public string $hashtags = '';

    public string $referenceFile = '';

    public array $formAttachments = [];

    public string $formAttachmentsJson = '[]';

    public string $commentText = '';

    public string $commentAttachments = '[]';

    public $newFileUpload = null;

    public bool $skipApproval = false;

    public ?int $selectedContentId = null;

    public bool $showContentDiscussion = false;

    public bool $showWorkflowForm = false;

    public bool $showPriorityForm = false;

    public ?int $workflowContentId = null;

    public string $workflowPriority = 'medium';

    public array $clients = [];

    public const PLATFORMS = ['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'linkedin'];

    public const TYPES = ['reel', 'post', 'story', 'video', 'carousel', 'blog'];

    public const STATUSES = ['draft', 'scripting', 'in-review', 'revision', 'published'];

    public array $contentByDate = [];

    public function mount(): void
    {
        if (NepaliDate::isBs()) {
            try {
                $bsDate = LaravelNepaliDate::from(now()->format('Y-m-d'))->toNepaliDateArray();
                $this->currentMonth = (int) $bsDate->month;
                $this->currentYear = (int) $bsDate->year;
            } catch (InvalidDateException) {
                $this->currentMonth = (int) now()->month;
                $this->currentYear = (int) now()->year;
            }
        } else {
            $this->currentMonth = (int) now()->month;
            $this->currentYear = (int) now()->year;
        }
        $this->loadClients();
        $this->loadMonthContent();
    }

    // Refresh on every request so the filter dropdown (which triggers no method
    // itself) and the form dropdown stay in sync with the clients table even
    // when the component was preserved by wire:navigate.
    public function hydrate(): void
    {
        $this->loadClients();
    }

    public function updatedFormAttachmentsJson(string $value): void
    {
        $this->formAttachments = json_decode($value, true) ?: [];
    }

    // Client filter list is a staff-only affordance — never expose the full
    // client roster to a client-portal session.
    protected function loadClients(): void
    {
        // Staff can plan for any non-deleted client — inactive/pending accounts
        // still need scheduled content. Only the client-portal guard gets an
        // empty list (they don't see the picker at all).
        $this->clients = Auth::guard('client')->check()
            ? []
            : DB::table('clients')->whereNull('deleted_at')->orderBy('name')->get()->toArray();
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

    public function prevMonth(): void
    {
        if ($this->currentMonth === 1) {
            $this->currentMonth = 12;
            $this->currentYear--;
        } else {
            $this->currentMonth--;
        }
        $this->loadMonthContent();
    }

    public function nextMonth(): void
    {
        if ($this->currentMonth === 12) {
            $this->currentMonth = 1;
            $this->currentYear++;
        } else {
            $this->currentMonth++;
        }
        $this->loadMonthContent();
    }

    public function goToday(): void
    {
        if (NepaliDate::isBs()) {
            try {
                $bsDate = LaravelNepaliDate::from(now()->format('Y-m-d'))->toNepaliDateArray();
                $this->currentMonth = (int) $bsDate->month;
                $this->currentYear = (int) $bsDate->year;
            } catch (InvalidDateException) {
                $this->currentMonth = (int) now()->month;
                $this->currentYear = (int) now()->year;
            }
        } else {
            $this->currentMonth = (int) now()->month;
            $this->currentYear = (int) now()->year;
        }
        $this->loadMonthContent();
    }

    public function loadMonthContent(): void
    {
        if (NepaliDate::isBs()) {
            try {
                $startBs = sprintf('%04d-%02d-01', $this->currentYear, $this->currentMonth);
                $startAd = LaravelNepaliDate::from($startBs, 'Y-m-d', 'np')->toEnglishDate('Y-m-d');
                $startCarbon = Carbon::parse($startAd);

                $totalDays = LaravelNepaliDate::daysInMonth($this->currentMonth, $this->currentYear);
            } catch (InvalidDateException|RuntimeException $e) {
                $startCarbon = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1);
                $totalDays = $startCarbon->daysInMonth;
            }
            $start = $startCarbon->copy()->startOfWeek(Carbon::SUNDAY);
            $end = $startCarbon->copy()->addDays($totalDays - 1)->endOfWeek(Carbon::SATURDAY);
        } else {
            $start = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->startOfWeek(Carbon::SUNDAY);
            $end = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->endOfMonth()->endOfWeek(Carbon::SATURDAY);
        }

        // ── Content items ──
        $query = DB::table('contents')
            ->leftJoin('clients', 'contents.client_id', '=', 'clients.id')
            ->whereNull('contents.deleted_at')
            ->whereBetween('contents.date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->select('contents.*', 'clients.name as client_name');

        // Exclude orphaned content whose linked workflows are ALL soft-deleted
        $query->whereNotIn('contents.id', function ($sub) {
            $sub->select('content_id')
                ->from('workflows')
                ->whereNotNull('content_id')
                ->groupBy('content_id')
                ->havingRaw('COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) = 0');
        });

        if ($account = Auth::guard('client')->user()) {
            $query->where('contents.client_id', $account->client_id);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('contents.title', 'like', "%{$this->search}%")
                    ->orWhere('clients.name', 'like', "%{$this->search}%");
            });
        }
        if ($this->platformFilter) {
            $filter = $this->platformFilter;
            $query->where(function ($q) use ($filter) {
                $q->whereJsonContains('contents.platform', $filter)
                    ->orWhereJsonContains('contents.platform', ContentTags::ALL);
            });
        }
        if ($this->statusFilter) {
            $query->where('contents.status', $this->statusFilter);
        }
        if ($this->clientFilter) {
            $query->where('contents.client_id', $this->clientFilter);
        }

        $allContent = $query->orderBy('contents.date')->get();

        // Load linked workflows and approvals for each content
        $contentIds = $allContent->pluck('id')->toArray();
        $workflowsByContent = DB::table('workflows')
            ->whereNull('deleted_at')
            ->whereIn('content_id', $contentIds)
            ->get()
            ->groupBy('content_id');
        $approvalsByContent = DB::table('approvals')
            ->whereNull('deleted_at')
            ->whereIn('content_id', $contentIds)
            ->get()
            ->groupBy('content_id');

        $this->contentByDate = [];
        foreach ($allContent as $item) {
            $item->_type = 'content';
            $item->_workflows = $workflowsByContent->get($item->id, collect());
            $item->_approvals = $approvalsByContent->get($item->id, collect());
            $this->contentByDate[$item->date][] = $item;
        }

        // ── Tasks / Shoots / Editing with due_date ──
        $taskQ = DB::table('tasks')
            ->leftJoin('clients', 'tasks.client_id', '=', 'clients.id')
            ->whereNull('tasks.deleted_at')
            ->whereBetween('tasks.due_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->whereNotNull('tasks.due_date')
            ->select('tasks.*', 'clients.name as client_name');

        // Exclude tasks whose linked workflow is soft-deleted
        $taskQ->where(function ($tq) {
            $tq->whereNull('tasks.workflow_id')
                ->orWhereIn('tasks.workflow_id', function ($sub) {
                    $sub->select('id')->from('workflows')->whereNull('deleted_at');
                });
        });

        if ($account = Auth::guard('client')->user()) {
            $taskQ->where('tasks.client_id', $account->client_id);
        }
        if ($this->search) {
            $taskQ->where(function ($q) {
                $q->where('tasks.title', 'like', "%{$this->search}%")
                    ->orWhere('clients.name', 'like', "%{$this->search}%");
            });
        }
        if ($this->clientFilter) {
            $taskQ->where('tasks.client_id', $this->clientFilter);
        }

        $allTasks = $taskQ->orderBy('tasks.due_date')->get();

        foreach ($allTasks as $task) {
            $task->_type = 'task';
            $this->contentByDate[$task->due_date][] = $task;
        }
    }

    public function updatedSearch(): void
    {
        $this->loadMonthContent();
    }

    public function updatedPlatformFilter(): void
    {
        $this->loadMonthContent();
    }

    public function updatedStatusFilter(): void
    {
        $this->loadMonthContent();
    }

    public function updatedClientFilter(): void
    {
        $this->loadMonthContent();
    }

    public function getCalendarDays(): array
    {
        if (NepaliDate::isBs()) {
            try {
                $startBs = sprintf('%04d-%02d-01', $this->currentYear, $this->currentMonth);
                $startAd = LaravelNepaliDate::from($startBs, 'Y-m-d', 'np')->toEnglishDate('Y-m-d');
                $firstDayCarbon = Carbon::parse($startAd);

                $totalDays = LaravelNepaliDate::daysInMonth($this->currentMonth, $this->currentYear);
            } catch (InvalidDateException|RuntimeException $e) {
                $firstDayCarbon = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1);
                $totalDays = $firstDayCarbon->daysInMonth;
            }

            $gridStart = $firstDayCarbon->copy()->startOfWeek(Carbon::SUNDAY);
            $gridEnd = $firstDayCarbon->copy()->addDays($totalDays - 1)->endOfWeek(Carbon::SATURDAY);

            $days = [];
            $current = $gridStart->copy();
            $bsDay = 0;

            while ($current->lte($gridEnd)) {
                $adDate = $current->format('Y-m-d');

                if ($current->format('Y-m-d') === $firstDayCarbon->format('Y-m-d')) {
                    $bsDay = 1;
                }

                $isCurrentMonth = $bsDay >= 1 && $bsDay <= $totalDays;

                $days[] = [
                    'carbon' => $current->copy(),
                    'ad_date' => $adDate,
                    'bs_date' => $isCurrentMonth ? sprintf('%04d-%02d-%02d', $this->currentYear, $this->currentMonth, $bsDay) : '',
                    'bs_day' => $isCurrentMonth ? $bsDay : 0,
                    'bs_month' => $isCurrentMonth ? $this->currentMonth : 0,
                    'is_current_bs_month' => $isCurrentMonth,
                ];

                if ($bsDay > 0) {
                    $bsDay++;
                }
                $current->addDay();
            }

            return $days;
        }

        $start = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->startOfWeek(Carbon::SUNDAY);
        $end = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $days = [];
        $current = $start->copy();
        while ($current->lte($end)) {
            $days[] = $current->copy();
            $current->addDay();
        }

        return $days;
    }

    public function getContentForDay(string $date): array
    {
        return $this->contentByDate[$date] ?? [];
    }

    public function openDayDetail(string $date): void
    {
        $this->selectedDate = $date;
        $this->showDayDetail = true;
    }

    public function getDayContent(): array
    {
        if (! $this->selectedDate) {
            return [];
        }

        return $this->contentByDate[$this->selectedDate] ?? [];
    }

    public function getDayContentCount(): int
    {
        return count($this->getDayContent());
    }

    public function getDayPlatformSummary(): array
    {
        $items = $this->getDayContent();
        $summary = [];
        foreach ($items as $item) {
            $platforms = ContentTags::expand(
                ContentTags::normalize($item->platform ?? null, 'platform'),
                'platform'
            );
            if (empty($platforms)) {
                $summary['unknown'] = ($summary['unknown'] ?? 0) + 1;

                continue;
            }
            foreach ($platforms as $p) {
                $summary[$p] = ($summary[$p] ?? 0) + 1;
            }
        }

        return $summary;
    }

    public function getContentLinks(): array
    {
        $items = $this->getDayContent();
        $ids = array_map(fn ($i) => $i->id, $items);
        if (empty($ids)) {
            return [];
        }

        $workflows = DB::table('workflows')
            ->whereIn('content_id', $ids)
            ->select('id', 'content_id', 'stage')
            ->get()
            ->keyBy('content_id');

        $approvals = DB::table('approvals')
            ->whereNull('deleted_at')
            ->whereIn('content_id', $ids)
            ->select('id', 'content_id', 'status')
            ->get()
            ->keyBy('content_id');

        $links = [];
        foreach ($ids as $id) {
            $links[$id] = [
                'workflow' => $workflows->get($id),
                'approval' => $approvals->get($id),
            ];
        }

        return $links;
    }

    public function openForm(?string $date = null): void
    {
        $this->resetForm();
        $this->loadClients();
        $this->selectedDate = $date ?? now()->format('Y-m-d');
        $this->formDate = $this->selectedDate;
        $this->formDueDate = '';
        $this->showForm = true;
        $this->showDayDetail = false;
    }

    public function editContent(int $id): void
    {
        $this->loadClients();
        $content = DB::table('contents')->where('id', $id)->first();
        if (! $content) {
            return;
        }

        $this->editingId = $id;
        $this->title = $content->title;
        $this->formClientId = $content->client_id ? (int) $content->client_id : null;
        $this->formDate = $content->date instanceof Carbon ? $content->date->format('Y-m-d') : $content->date;
        $this->formDueDate = $content->due_date instanceof Carbon ? $content->due_date->format('Y-m-d') : ($content->due_date ?? '');
        $this->formPlatforms = ContentTags::normalize($content->platform, 'platform');
        $this->formTypes = ContentTags::normalize($content->type, 'type');
        $this->formStatus = $content->status;
        $this->formAssigneeIds = is_array($content->assignee) ? $content->assignee : ($content->assignee ? [$content->assignee] : []);
        $this->caption = $content->caption ?? '';
        $this->hashtags = $content->hashtags ?? '';
        $this->referenceFile = $content->reference_file ?? '';
        $raw = $content->attachments;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $this->formAttachments = is_array($decoded) ? $decoded : [];
        } else {
            $this->formAttachments = is_array($raw) ? $raw : [];
        }
        $this->formAttachmentsJson = json_encode($this->formAttachments);
        $this->showForm = true;
        $this->showDayDetail = false;
        $this->showContentDiscussion = false; // Close discussion modal when editing
    }

    public function save(): void
    {
        $isBs = NepaliDate::isBs();

        $rules = [
            'title' => 'required|string|max:255',
            'formClientId' => 'nullable|integer',
            'formPlatforms' => 'required|array|min:1',
            'formTypes' => 'required|array|min:1',
            'formStatus' => 'required|string|in:draft,scripting,in-review,revision,published',
        ];

        if (! $isBs) {
            $rules['formDate'] = 'required|date';
            $rules['formDueDate'] = 'nullable|date';
        } else {
            $rules['formDate'] = 'required|string';
            $rules['formDueDate'] = 'nullable|string';
        }

        $this->validate($rules);

        try {
            // Normalize + de-dupe (e.g. someone ticks Instagram AND "All" — collapse to ["all"])
            $platforms = ContentTags::normalize($this->formPlatforms, 'platform');
            $types = ContentTags::normalize($this->formTypes, 'type');
            if (in_array(ContentTags::ALL, $platforms, true)) {
                $platforms = [ContentTags::ALL];
            }
            if (in_array(ContentTags::ALL, $types, true)) {
                $types = [ContentTags::ALL];
            }

            if ($this->editingId) {
                // Check if assignee is changing
                $oldContent = DB::table('contents')->where('id', $this->editingId)->first();
                $oldAssigneeRaw = $oldContent->assignee ?? null;
                $oldAssignee = is_string($oldAssigneeRaw) ? (json_decode($oldAssigneeRaw, true) ?? []) : (is_array($oldAssigneeRaw) ? $oldAssigneeRaw : []);
                $newAssignee = ! empty($this->formAssigneeIds) ? $this->formAssigneeIds : [];

                DB::table('contents')->where('id', $this->editingId)->update([
                    'title' => $this->title,
                    'client_id' => $this->formClientId,
                    'date' => $this->formDate,
                    'due_date' => $this->formDueDate ?: null,
                    'platform' => json_encode($platforms),
                    'type' => json_encode($types),
                    'status' => $this->formStatus,
                    'assignee' => json_encode($newAssignee),
                    'caption' => $this->caption,
                    'hashtags' => $this->hashtags,
                    'reference_file' => $this->referenceFile,
                    'attachments' => $this->formAttachments ? array_values($this->formAttachments) : null,
                    'updated_at' => now(),
                ]);

                // Notify new assignees (those in new list but not in old)
                $contentModel = Content::find($this->editingId);
                if ($contentModel) {
                    $actorId = Auth::id();
                    foreach ($newAssignee as $uid) {
                        if (! in_array($uid, $oldAssignee) && (int) $uid !== $actorId) {
                            $user = User::find($uid);
                            if ($user) {
                                $user->notify(new ContentAssignedNotification($contentModel, Auth::user()));
                            }
                        }
                    }
                }

                // Sync assignees and date to linked workflows
                if (! self::$syncingWorkflow) {
                    self::$syncingWorkflow = true;

                    try {
                        // Map content status to workflow stage
                        $workflowStage = match ($this->formStatus) {
                            'draft' => 'todo',
                            'scripting' => 'scripting',
                            'in-review' => 'review',
                            'revision' => 'revision',
                            'published' => 'published',
                            default => 'todo',
                        };

                        DB::table('workflows')
                            ->where('content_id', $this->editingId)
                            ->whereNull('deleted_at')
                            ->update([
                                'assignee' => json_encode($newAssignee),
                                'deadline' => $this->formDate,
                                'stage' => $workflowStage,
                                'updated_at' => now(),
                            ]);
                    } catch (Exception $e) {
                        // Log but don't fail the content update if workflow sync fails
                        logger()->error('Failed to sync content to workflow', [
                            'content_id' => $this->editingId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    self::$syncingWorkflow = false;
                }

                $this->dispatch('toast', message: 'Content updated successfully', type: 'success');
            } else {
                $assigneeVal = ! empty($this->formAssigneeIds) ? json_encode($this->formAssigneeIds) : null;
                $newContentId = DB::table('contents')->insertGetId([
                    'title' => $this->title,
                    'client_id' => $this->formClientId ?: null,
                    'date' => $this->formDate,
                    'due_date' => $this->formDueDate ?: null,
                    'platform' => json_encode($platforms),
                    'type' => json_encode($types),
                    'status' => $this->formStatus,
                    'assignee' => $assigneeVal,
                    'caption' => $this->caption,
                    'hashtags' => $this->hashtags,
                    'reference_file' => $this->referenceFile,
                    'attachments' => $this->formAttachments ? array_values($this->formAttachments) : null,
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // If "Create Workflow Immediately" is checked, create workflow and skip approval
                if ($this->skipApproval && $newContentId) {
                    $this->validate([
                        'workflowPriority' => 'required|in:low,medium,high,urgent',
                    ]);

                    // Update content status to in-review (it's now in workflow)
                    DB::table('contents')->where('id', $newContentId)->update([
                        'status' => 'in-review',
                        'updated_at' => now(),
                    ]);

                    // Get first workflow stage
                    $firstStage = DB::table('workflow_stages')->orderBy('order')->first();

                    // Get primary type for workflow
                    $primaryType = ContentTags::primary($types, 'type');

                    // Create workflow item directly (no approval needed)
                    $workflowId = DB::table('workflows')->insertGetId([
                        'title' => $this->title,
                        'client_id' => $this->formClientId ?: null,
                        'content_id' => $newContentId,
                        'type' => $primaryType,
                        'stage' => $firstStage->key ?? 'todo',
                        'priority' => $this->workflowPriority ?: 'medium',
                        'assignee' => $assigneeVal,
                        'deadline' => $this->formDate,
                        'attachments' => $this->formAttachments ? json_encode(array_values($this->formAttachments)) : null,
                        'submitted_by' => auth()->id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Notify assignees about workflow creation
                    if (! empty($this->formAssigneeIds)) {
                        $actorId = Auth::id();
                        $workflowModel = Workflow::find($workflowId);
                        if ($workflowModel) {
                            foreach ($this->formAssigneeIds as $uid) {
                                if ((int) $uid !== $actorId) {
                                    $assigneeUser = User::find($uid);
                                    if ($assigneeUser) {
                                        $assigneeUser->notify(new WorkflowAssignedNotification($workflowModel, Auth::user()));
                                    }
                                }
                            }
                        }
                    }

                    // Track package usage for workflow (only for client content)
                    if ($this->formClientId) {
                        PackageService::recordWorkflow($this->formClientId);
                    }

                    app(ActivityLogger::class)->record(Auth::user(), "Content '{$this->title}' created with workflow (approval skipped)");

                    $this->dispatch('toast', message: 'Content and workflow created successfully (approval skipped)', type: 'success');
                } else {
                    // Normal flow: just notify assignees about content creation
                    if (! empty($this->formAssigneeIds)) {
                        $contentModel = Content::find($newContentId);
                        $actorId = Auth::id();
                        if ($contentModel) {
                            foreach ($this->formAssigneeIds as $uid) {
                                if ((int) $uid !== $actorId) {
                                    $user = User::find($uid);
                                    if ($user) {
                                        $user->notify(new ContentAssignedNotification($contentModel, Auth::user()));
                                    }
                                }
                            }
                        }
                    }

                    $this->dispatch('toast', message: 'Content created successfully', type: 'success');
                }

                // Package usage counter still bumps per selected type (expand "all" sentinel)
                if ($this->formClientId) {
                    foreach (ContentTags::expand($types, 'type') as $type) {
                        PackageService::recordContent(
                            $this->formClientId,
                            $this->formStatus === 'published' ? 'published' : 'created',
                            $type,
                        );
                    }
                }
            }

            $this->showForm = false;
            $this->resetForm();
            $this->loadMonthContent();
        } catch (Exception $e) {
            $this->dispatch('toast', message: 'Error saving content: ' . $e->getMessage(), type: 'error');
        }
    }

    public function deleteContent(int $id): void
    {
        abort_unless(Auth::check(), 403);
        $content = Content::findOrFail($id);
        // Admin / super-admin can delete any status; other roles keep the status guard
        // (and the UI hides the button for them anyway — this is the wire:call defence).
        $isPrivileged = in_array(Auth::user()->role, ['super-admin', 'admin'], true);
        if (! $isPrivileged && in_array($content->status, ['published', 'in-review', 'scheduled'])) {
            $this->dispatch('toast', message: 'Cannot delete content with status: ' . $content->status, type: 'error');

            return;
        }

        // Soft-delete linked workflows so they don't linger on the kanban board
        DB::table('workflows')->where('content_id', $id)->whereNull('deleted_at')->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $content->delete();
        $this->loadMonthContent();
        $this->dispatch('contentUpdated');
        $this->dispatch('toast', message: 'Content moved to trash', type: 'success');
    }

    public function openWorkflowForm(int $contentId): void
    {
        $this->workflowContentId = $contentId;
        $this->workflowPriority = 'medium'; // Reset to default
        $this->showWorkflowForm = true;
        $this->showContentDiscussion = false;
    }

    public function createWorkflowFromContent(): void
    {
        $this->validate([
            'workflowPriority' => 'required|in:low,medium,high,urgent',
        ]);

        $content = Content::findOrFail($this->workflowContentId);

        // Check if workflow already exists for this content
        $existing = Workflow::where('content_id', $content->id)->whereNull('deleted_at')->first();
        if ($existing) {
            $this->dispatch('toast', message: 'Workflow already exists for this content!', type: 'warning');
            $this->showWorkflowForm = false;
            $this->workflowContentId = null;
            $this->workflowPriority = 'medium';

            return;
        }

        // Get primary type for workflow
        $types = is_array($content->type) ? $content->type : json_decode($content->type ?? '[]', true);
        $primaryType = !empty($types) ? $types[0] : 'content';

        // Get assignee from content
        $assignee = is_array($content->assignee) ? $content->assignee : json_decode($content->assignee ?? '[]', true);

        Workflow::create([
            'title' => $content->title,
            'client_id' => $content->client_id,
            'content_id' => $content->id,
            'type' => $primaryType,
            'stage' => 'todo',
            'priority' => $this->workflowPriority,
            'deadline' => $content->date,
            'assignee' => $assignee ?: null,
            'description_html' => $content->caption,
            'status' => 'active',
            'submitted_by' => Auth::id(),
        ]);

        $this->showWorkflowForm = false;
        $this->workflowContentId = null;
        $this->workflowPriority = 'medium';
        $this->loadMonthContent(); // Reload to update calendar
        $this->dispatch('toast', message: 'Workflow created successfully!', type: 'success');
        $this->dispatch('contentUpdated');
    }

    public function openPriorityForm(int $contentId): void
    {
        $this->workflowContentId = $contentId;
        $workflow = Workflow::where('content_id', $contentId)->whereNull('deleted_at')->first();
        $this->workflowPriority = $workflow?->priority ?? 'medium';
        $this->showPriorityForm = true;
        $this->showContentDiscussion = false;
    }

    public function updateContentPriority(): void
    {
        $this->validate([
            'workflowPriority' => 'required|in:low,medium,high,urgent',
        ]);

        $workflow = Workflow::where('content_id', $this->workflowContentId)->whereNull('deleted_at')->first();

        if ($workflow) {
            $workflow->update(['priority' => $this->workflowPriority]);
            $this->dispatch('toast', message: 'Priority updated successfully!', type: 'success');
        } else {
            $this->dispatch('toast', message: 'No workflow found for this content!', type: 'error');
        }

        $this->showPriorityForm = false;
        $this->workflowContentId = null;
        $this->workflowPriority = 'medium';
        $this->loadMonthContent(); // Reload to update calendar
        $this->dispatch('contentUpdated');
    }

    public function getStats(): array
    {
        $query = DB::table('contents')->whereNull('deleted_at');

        // Exclude orphaned content whose linked workflows are ALL soft-deleted
        $query->whereNotIn('id', function ($sub) {
            $sub->select('content_id')
                ->from('workflows')
                ->whereNotNull('content_id')
                ->groupBy('content_id')
                ->havingRaw('COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) = 0');
        });

        // Tenant isolation — clients see stats for their own content only.
        if ($account = Auth::guard('client')->user()) {
            $query->where('client_id', $account->client_id);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', "%{$this->search}%");
            });
        }
        if ($this->platformFilter) {
            $filter = $this->platformFilter;
            $query->where(function ($q) use ($filter) {
                $q->whereJsonContains('platform', $filter)
                    ->orWhereJsonContains('platform', ContentTags::ALL);
            });
        }
        if ($this->clientFilter) {
            $query->where('client_id', $this->clientFilter);
        }

        return $query->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    public function getListContent()
    {
        $query = DB::table('contents')
            ->leftJoin('clients', 'contents.client_id', '=', 'clients.id')
            ->whereNull('contents.deleted_at')
            ->select('contents.*', 'clients.name as client_name')
            ->orderBy('contents.date', 'desc');

        // Exclude orphaned content whose linked workflows are ALL soft-deleted
        $query->whereNotIn('contents.id', function ($sub) {
            $sub->select('content_id')
                ->from('workflows')
                ->whereNotNull('content_id')
                ->groupBy('content_id')
                ->havingRaw('COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) = 0');
        });

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('contents.title', 'like', "%{$this->search}%")
                    ->orWhere('clients.name', 'like', "%{$this->search}%");
            });
        }
        if ($this->platformFilter) {
            $filter = $this->platformFilter;
            $query->where(function ($q) use ($filter) {
                $q->whereJsonContains('contents.platform', $filter)
                    ->orWhereJsonContains('contents.platform', ContentTags::ALL);
            });
        }
        if ($this->statusFilter) {
            $query->where('contents.status', $this->statusFilter);
        }
        if ($this->clientFilter) {
            $query->where('contents.client_id', $this->clientFilter);
        }

        $results = $query->get();

        // Load linked workflows and approvals for each content
        $contentIds = $results->pluck('id')->toArray();
        $workflowsByContent = DB::table('workflows')
            ->whereNull('deleted_at')
            ->whereIn('content_id', $contentIds)
            ->get()
            ->groupBy('content_id');
        $approvalsByContent = DB::table('approvals')
            ->whereNull('deleted_at')
            ->whereIn('content_id', $contentIds)
            ->get()
            ->groupBy('content_id');

        foreach ($results as $item) {
            $item->_workflows = $workflowsByContent->get($item->id, collect());
            $item->_approvals = $approvalsByContent->get($item->id, collect());
        }

        return $results->toArray();
    }

    public function getPlatformData(): array
    {
        return $this->tallyContentColumn('platform');
    }

    public function getTypeData(): array
    {
        return $this->tallyContentColumn('type');
    }

    /**
     * Flat-count a JSON-array content column, expanding the "all" sentinel to every
     * canonical value so a single ["all"] row contributes 1 to each platform/type.
     */
    private function tallyContentColumn(string $kind): array
    {
        $rows = DB::table('contents')->whereNull('deleted_at')->pluck($kind);
        $counts = [];
        foreach ($rows as $raw) {
            $expanded = ContentTags::expand(
                ContentTags::normalize($raw, $kind),
                $kind
            );
            foreach ($expanded as $v) {
                $counts[$v] = ($counts[$v] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    public function submitForApproval(int $contentId): void
    {
        $content = DB::table('contents')->where('id', $contentId)->first();
        if (! $content) {
            return;
        }

        if ($content->status === 'published') {
            $this->dispatch('toast', message: 'Published content cannot be changed', type: 'error');

            return;
        }

        if ($content->status === 'in-review') {
            $this->dispatch('toast', message: 'Content is already submitted for approval', type: 'info');

            return;
        }

        // Advance: draft → scripting, scripting → in-review
        $next = match ($content->status) {
            'draft' => 'scripting',
            'scripting' => 'in-review',
            'revision' => 'in-review',
            default => null,
        };

        if (! $next) {
            $this->dispatch('toast', message: 'Cannot advance from this status', type: 'error');

            return;
        }

        DB::table('contents')->where('id', $contentId)->update([
            'status' => $next,
            'updated_at' => now(),
        ]);

        // If moving to in-review, create Approval #1
        if ($next === 'in-review') {
            DB::table('contents')->where('id', $contentId)->update([
                'submitted_for_approval_at' => now(),
            ]);

            $_platformArr = ContentTags::normalize($content->platform, 'platform');
            $_typeArr = ContentTags::normalize($content->type, 'type');
            $platform = ContentTags::label($_platformArr, 'platform');
            $type = ContentTags::label($_typeArr, 'type');
            $contentAttachments = $content->attachments ? (is_string($content->attachments) ? $content->attachments : json_encode($content->attachments)) : null;
            DB::table('approvals')->insert([
                'title' => $content->title . " ({$platform} / {$type})",
                'client_id' => $content->client_id,
                'content_id' => $contentId,
                'type' => ContentTags::primary($_typeArr, 'type'),
                'status' => 'pending',
                'approval_stage' => 'first',
                'submitted_by' => auth()->id(),
                'attachments' => $contentAttachments,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(NotificationService::class)->notifyContentSubmittedForApproval($content->title, $contentId);
            $this->dispatch('toast', message: 'Content submitted for approval', type: 'success');
        } else {
            $this->dispatch('toast', message: 'Status changed to ' . str_replace('-', ' ', ucfirst($next)), type: 'success');
        }

        $this->loadMonthContent();
    }

    /**
     * Send content directly to workflow, bypassing Approval #1
     */
    public function sendToWorkflowDirectly(int $contentId): void
    {
        $content = DB::table('contents')->where('id', $contentId)->first();
        if (! $content) {
            return;
        }

        if ($content->status === 'published') {
            $this->dispatch('toast', message: 'Published content cannot be changed', type: 'error');

            return;
        }

        // Check if workflow already exists
        $existingWorkflow = DB::table('workflows')
            ->where('content_id', $contentId)
            ->whereNull('deleted_at')
            ->first();

        if ($existingWorkflow) {
            $this->dispatch('toast', message: 'Workflow already exists for this content', type: 'info');

            return;
        }

        // Update content status to in-review (it's now in workflow)
        DB::table('contents')->where('id', $contentId)->update([
            'status' => 'in-review',
            'updated_at' => now(),
        ]);

        // Get first workflow stage
        $firstStage = DB::table('workflow_stages')->orderBy('order')->first();

        // Prepare attachments
        $contentAttachments = $content->attachments;
        if (is_string($contentAttachments)) {
            $decoded = json_decode($contentAttachments, true);
            $contentAttachments = is_array($decoded) ? $decoded : null;
        }

        // Get primary type for workflow
        $_typeArr = ContentTags::normalize($content->type, 'type');
        $primaryType = ContentTags::primary($_typeArr, 'type');

        // Create workflow item directly
        $workflowId = DB::table('workflows')->insertGetId([
            'title' => $content->title,
            'client_id' => $content->client_id,
            'content_id' => $contentId,
            'type' => $primaryType,
            'stage' => $firstStage->key ?? 'todo',
            'priority' => 'medium',
            'assignee' => $content->assignee ?? null,
            'deadline' => $content->date,
            'attachments' => $contentAttachments ? json_encode($contentAttachments) : null,
            'submitted_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Notify assignees
        if (! empty($content->assignee)) {
            $assigneeIds = is_string($content->assignee) ? json_decode($content->assignee, true) : $content->assignee;
            if (is_array($assigneeIds)) {
                $actorId = Auth::id();
                foreach ($assigneeIds as $uid) {
                    if ((int) $uid !== $actorId) {
                        $assigneeUser = User::find($uid);
                        if ($assigneeUser) {
                            $workflowModel = Workflow::find($workflowId);
                            if ($workflowModel) {
                                $assigneeUser->notify(new WorkflowAssignedNotification($workflowModel, Auth::user()));
                            }
                        }
                    }
                }
            }
        }

        // Track package usage (only for client content)
        if ($content->client_id) {
            PackageService::recordWorkflow($content->client_id);
        }

        app(ActivityLogger::class)->record(Auth::user(), "Content '{$content->title}' sent directly to workflow (skipped approval)");

        $this->dispatch('toast', message: 'Content sent directly to workflow (approval skipped)', type: 'success');
        $this->loadMonthContent();
    }

    public function openDiscussion(int $contentId): void
    {
        $this->selectedContentId = $contentId;
        $this->showContentDiscussion = true;
        $this->showDayDetail = false;
    }

    public function getDiscussionAttachments(): array
    {
        if (! $this->selectedContentId) {
            return [];
        }
        $atts = [];
        $seenIds = [];

        // Helper to add attachment if not already seen
        $addAtt = function ($a) use (&$atts, &$seenIds) {
            if (! is_array($a)) {
                return;
            }
            $key = $a['id'] ?? ($a['url'] ?? md5(json_encode($a)));
            if (! in_array($key, $seenIds)) {
                $seenIds[] = $key;
                $atts[] = $a;
            }
        };

        // 1. Content's own attachments
        $raw = DB::table('contents')->where('id', $this->selectedContentId)->value('attachments');
        if (! is_null($raw)) {
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            if (is_array($raw)) {
                foreach (array_filter($raw, fn ($a) => is_array($a)) as $a) {
                    $addAtt($a);
                }
            }
        }

        // 2. Workflow attachments linked to this content
        $wfAtts = DB::table('workflows')->where('content_id', $this->selectedContentId)->whereNotNull('attachments')->get();
        foreach ($wfAtts as $wf) {
            if (empty($wf->attachments)) {
                continue;
            }
            $decoded = is_string($wf->attachments) ? json_decode($wf->attachments, true) : $wf->attachments;
            if (is_array($decoded)) {
                foreach (array_filter($decoded, fn ($a) => is_array($a)) as $a) {
                    $addAtt($a);
                }
            }
        }

        // 3. Task attachments (tasks linked to workflows linked to this content)
        $taskAtts = DB::table('tasks')
            ->join('workflows', 'tasks.workflow_id', '=', 'workflows.id')
            ->whereNull('tasks.deleted_at')
            ->where('workflows.content_id', $this->selectedContentId)
            ->whereNotNull('tasks.attachments')
            ->pluck('tasks.attachments');
        foreach ($taskAtts as $rawTaskAtts) {
            if (is_string($rawTaskAtts)) {
                $rawTaskAtts = json_decode($rawTaskAtts, true);
            }
            if (is_array($rawTaskAtts)) {
                foreach (array_filter($rawTaskAtts, fn ($a) => is_array($a)) as $a) {
                    $addAtt($a);
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

    public function getContentDiscussionComments()
    {
        if (! $this->selectedContentId) {
            return collect();
        }

        return Comment::with('user')
            ->where('commentable_type', Content::class)
            ->where('commentable_id', $this->selectedContentId)
            ->latest()
            ->get();
    }

    public function addContentComment(string $attachmentsJson = '[]'): void
    {
        $hasText = trim($this->commentText) !== '';
        $attachments = json_decode($attachmentsJson, true) ?: [];
        $hasFiles = ! empty($attachments);

        if (! $hasText && ! $hasFiles) {
            return;
        }

        if (! $this->selectedContentId) {
            return;
        }

        $content = DB::table('contents')->where('id', $this->selectedContentId)->first();
        if (! $content) {
            return;
        }

        if ($hasFiles && ! $hasText) {
            $existingRaw = DB::table('contents')->where('id', $this->selectedContentId)->value('attachments');
            $existing = $existingRaw ? (json_decode($existingRaw, true) ?: []) : [];
            $merged = array_values(array_merge($existing, $attachments));

            DB::table('contents')->where('id', $this->selectedContentId)->update([
                'attachments' => json_encode($merged),
                'updated_at' => now(),
            ]);

            // Notify assigned user + admins (not self)
            $contentModel = Content::find($this->selectedContentId);
            if ($contentModel) {
                $this->notifyContentRecipients(
                    $contentModel,
                    new ContentAttachedNotification($contentModel, $attachments, Auth::user())
                );
            }

            $this->commentAttachments = '[]';
            $this->loadMonthContent();
            $this->dispatch('contentUpdated');
            $this->dispatch('toast', message: 'Files attached', type: 'success');

            return;
        }

        $comment = Comment::create([
            'commentable_type' => Content::class,
            'commentable_id' => $this->selectedContentId,
            'user_id' => Auth::id(),
            'user_type' => User::class,
            'body' => $this->commentText,
            'attachments' => $attachments ?: null,
        ]);

        app(ActivityLogger::class)->record(
            Auth::user(),
            "Commented on content #{$this->selectedContentId}"
        );

        // Notify assigned user + admins (not self)
        $contentModel = Content::find($this->selectedContentId);
        if ($contentModel) {
            $this->notifyContentRecipients(
                $contentModel,
                new ContentCommentNotification($comment, $contentModel, Auth::user())
            );
        }

        $this->commentText = '';
        $this->commentAttachments = '[]';
        $this->dispatch('tiptap-set-content', name: 'calComment', html: '');
        $this->dispatch('toast', message: 'Comment added', type: 'success');
    }

    private function notifyContentRecipients($content, $notification): void
    {
        $actorId = Auth::id();
        $recipientIds = collect();

        if ($content->assignee) {
            $assigneeIds = is_array($content->assignee) ? $content->assignee : json_decode($content->assignee, true);
            if (is_array($assigneeIds)) {
                $recipientIds = $recipientIds->merge($assigneeIds);
            }
        }

        // Admins/managers only (NOT super-admin)
        $adminIds = User::whereIn('role', ['admin', 'manager'])
            ->where('status', 'active')
            ->pluck('id');
        $recipientIds = $recipientIds->merge($adminIds);
        $recipientIds = $recipientIds->filter(fn ($id) => (int) $id !== (int) $actorId)->unique();

        $actor = Auth::user();
        if ($recipientIds->isNotEmpty()) {
            $recipients = User::whereIn('id', $recipientIds)->where('status', 'active')->get();
            if ($actor && ! empty($actor->email)) {
                $recipients = $recipients->reject(fn ($u) => strtolower(trim($u->email)) === strtolower(trim($actor->email)));
            }
            foreach ($recipients as $recipient) {
                $recipient->notify($notification);
            }
        }

        // Also notify client accounts linked to this content's client
        if ($content->client_id) {
            $clientRecipients = ClientAccount::where('client_id', $content->client_id)
                ->where('status', 'active')
                ->get();
            if ($actor && ! empty($actor->email)) {
                $clientRecipients = $clientRecipients->reject(fn ($c) => strtolower(trim($c->email)) === strtolower(trim($actor->email)));
            }
            foreach ($clientRecipients as $clientRecipient) {
                $clientRecipient->notify($notification);
            }
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

    public function toggleAllPlatforms(): void
    {
        $this->formPlatforms = in_array(ContentTags::ALL, $this->formPlatforms, true) ? [] : [ContentTags::ALL];
    }

    public function toggleAllTypes(): void
    {
        $this->formTypes = in_array(ContentTags::ALL, $this->formTypes, true) ? [] : [ContentTags::ALL];
    }

    public function updatedFormPlatforms(): void
    {
        // If "All" and individual values coexist, "All" takes precedence — collapse to sentinel.
        if (in_array(ContentTags::ALL, $this->formPlatforms, true) && count($this->formPlatforms) > 1) {
            $this->formPlatforms = [ContentTags::ALL];
        }
    }

    public function updatedFormTypes(): void
    {
        if (in_array(ContentTags::ALL, $this->formTypes, true) && count($this->formTypes) > 1) {
            $this->formTypes = [ContentTags::ALL];
        }
    }

    private function resetForm(): void
    {
        $this->editingId = 0;
        $this->title = '';
        $this->formClientId = null;
        $this->formDate = '';
        $this->formDueDate = '';
        $this->formPlatforms = [];
        $this->formTypes = [];
        $this->formStatus = 'draft';
        $this->formAssigneeIds = [];
        $this->caption = '';
        $this->hashtags = '';
        $this->referenceFile = '';
        $this->formAttachments = [];
        $this->formAttachmentsJson = '[]';
        $this->skipApproval = false;
        $this->workflowPriority = 'medium';
    }

    #[On('confirm-resolved')]
    public function onConfirmResolved(string $action, array $params = []): void
    {
        if ($action !== '' && method_exists($this, $action)) {
            $this->{$action}(...$params);
        }
    }
}; ?>

<div x-data="{ formOpen: @js($showForm) }" x-effect="$wire.showForm ? (formOpen = true) : (formOpen = false)">
    {{-- ========== HEADER ========== --}}
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-900">Content Planner</h1>
            <p class="text-sm text-gray-500 mt-1">Plan, schedule, and manage your content</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5">
                <button
                    wire:click="$set('viewMode', 'month')"
                    class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $viewMode === 'month' ? 'bg-[var(--brand)] text-white shadow-sm' : 'text-gray-500 hover:bg-gray-50' }}"
                >
                    <i class="fas fa-calendar-alt mr-1"></i> Month
                </button>
                <button
                    wire:click="$set('viewMode', 'list')"
                    class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $viewMode === 'list' ? 'bg-[var(--brand)] text-white shadow-sm' : 'text-gray-500 hover:bg-gray-50' }}"
                >
                    <i class="fas fa-list mr-1"></i> List
                </button>
            </div>
            <button wire:click="openForm" class="btn btn-primary btn-sm">
                <i class="fas fa-plus text-xs"></i> Add Content
            </button>
        </div>
    </div>

    {{-- ========== FILTERS BAR ========== --}}
    <div class="mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <div>
            <label class="form-label">Search</label>
            <x-search-input wire="search" placeholder="Search content..." />
        </div>
        <div>
            <label class="form-label">Platform</label
            ><select wire:model.live="platformFilter" class="form-select">
                <option value="">All Platforms</option>
                @foreach (self::PLATFORMS as $p)
                    <option value="{{ $p }}">{{ ucfirst($p) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Status</label
            ><select wire:model.live="statusFilter" class="form-select">
                <option value="">All Status</option>
                @foreach (self::STATUSES as $s)
                    <option value="{{ $s }}">{{ str_replace('-', ' ', ucfirst($s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Client</label
            ><select wire:model.live="clientFilter" class="form-select">
                <option value="">All Clients</option>
                @foreach ($clients as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ========== STATS ROW ========== --}}
    @php $stats = $this->getStats(); @endphp
    <div class="mb-4 flex flex-wrap gap-2">
        @php
            $allStatuses = [
                'draft' => ['icon' => 'fa-pencil', 'color' => 'bg-gray-100 text-gray-600'],
                'scripting' => ['icon' => 'fa-file-alt', 'color' => 'bg-amber-50 text-amber-600'],
                'in-review' => ['icon' => 'fa-eye', 'color' => 'bg-orange-50 text-orange-600'],
                'scheduled' => ['icon' => 'fa-clock', 'color' => 'bg-blue-50 text-blue-600'],
                'published' => ['icon' => 'fa-globe', 'color' => 'bg-green-50 text-green-600'],
            ];
        @endphp
        @foreach ($allStatuses as $key => $info)
            <button
                wire:click="$set('statusFilter', '{{ $statusFilter === $key ? '' : $key }}')"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-all {{ $statusFilter === $key ? 'ring-2 ring-offset-1 ring-[var(--brand)]' : '' }} {{ $info['color'] }}"
            >
                <i class="fas {{ $info['icon'] }} text-[10px]"></i>
                {{ str_replace('-', ' ', ucfirst($key)) }}
                <span class="ml-0.5 bg-white/60 rounded-full px-1.5 py-0.5 text-[10px]">{{ $stats[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    {{-- ========== TYPE LEGEND ========== --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <span class="text-xs font-semibold text-gray-500">Content Types:</span>
        @php
            $typeColors = [
                'reel' => ['label' => 'Reels', 'color' => 'bg-pink-500'],
                'post' => ['label' => 'Posts', 'color' => 'bg-blue-500'],
                'story' => ['label' => 'Stories', 'color' => 'bg-purple-500'],
                'video' => ['label' => 'Videos', 'color' => 'bg-red-500'],
                'carousel' => ['label' => 'Carousels', 'color' => 'bg-amber-500'],
                'blog' => ['label' => 'Blogs', 'color' => 'bg-green-500'],
            ];
        @endphp
        @foreach ($typeColors as $type => $info)
            <div class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                <div class="w-3 h-3 rounded {{ $info['color'] }}"></div>
                <span>{{ $info['label'] }}</span>
            </div>
        @endforeach
    </div>

    {{-- ========== MONTH VIEW ========== --}}
    @if ($viewMode === 'month')
        <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
            {{-- Month Navigation --}}
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                <div class="flex items-center gap-2">
                    <button wire:click="prevMonth" class="btn btn-ghost btn-sm" title="Previous Month">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                    <h3 class="text-sm font-bold text-gray-900 min-w-[160px] text-center">
                        @if (\App\Support\NepaliDate::isBs())
                            {{ \App\Support\NepaliDate::bsMonthName($currentMonth) }} {{ $currentYear }}
                        @else
                            {{ Carbon::createFromDate($currentYear, $currentMonth, 1)->format('F Y') }}
                        @endif
                    </h3>
                    <button wire:click="nextMonth" class="btn btn-ghost btn-sm" title="Next Month">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                </div>
                <button wire:click="goToday" class="btn btn-secondary btn-sm">
                    <i class="fas fa-calendar-day text-xs"></i> Today
                </button>
            </div>

            {{-- Weekday Headers --}}
            <div class="grid grid-cols-7 border-b border-gray-100">
                @foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day)
                    <div
                        class="px-2 py-2 text-center text-[11px] font-bold uppercase tracking-wider text-gray-400 {{ in_array($day, ['Sat','Sun']) ? 'bg-gray-50/50' : '' }}"
                    >
                        {{ $day }}
                    </div>
                @endforeach
            </div>

            {{-- Calendar Grid --}}
            @php
                $monthDays = $this->getCalendarDays();
                $todayAd = now()->format('Y-m-d');
                $isBsMode = \App\Support\NepaliDate::isBs();
            @endphp
            <div class="grid grid-cols-7">
                @foreach ($monthDays as $day)
                    @php
                        if ($isBsMode) {
                            $dateStr = $day['ad_date'];
                            $isToday = $dateStr === $todayAd;
                            $isWeekend = in_array($day['carbon']->dayOfWeek, [0, 6]);
                            $isOtherMonth = !$day['is_current_bs_month'];
                            $dayContent = $this->getContentForDay($dateStr);
                            $dayNumber = $day['bs_day'];
                        } else {
                            $dateStr = $day->format('Y-m-d');
                            $isToday = $dateStr === $todayAd;
                            $isWeekend = in_array($day->dayOfWeek, [0, 6]);
                            $isOtherMonth = $day->month !== $currentMonth;
                            $dayContent = $this->getContentForDay($dateStr);
                            $dayNumber = (int) $day->format('j');
                        }
                    @endphp
                    <div
                        class="cal-day {{ $isToday ? 'today' : '' }} {{ $isWeekend && !$isOtherMonth ? 'weekend' : '' }} {{ $isOtherMonth ? 'other-month' : '' }}"
                        wire:click="openDayDetail('{{ $dateStr }}')"
                        wire:loading.class="opacity-50"
                    >
                        <div class="flex items-center justify-between mb-1">
                            <span
                                class="text-[11px] font-semibold {{ $isToday ? 'bg-[var(--brand)] text-white w-5 h-5 rounded-full flex items-center justify-center' : ($isOtherMonth ? 'text-gray-300' : 'text-gray-600') }}"
                            >
                                {{ ($isBsMode && $dayNumber <= 0) ? '' : $dayNumber }}
                            </span>
                            @if (count($dayContent) > 0)
                                <span
                                    class="text-[9px] font-bold {{ $isToday ? 'text-[var(--brand)]' : 'text-gray-400' }} bg-gray-100 rounded-full px-1.5 py-0.5 leading-none"
                                    >{{ count($dayContent) }}</span
                                >
                            @endif
                        </div>
                        <div class="space-y-0.5" @click.stop>
                            @foreach (array_slice($dayContent, 0, 3) as $item)
                                @if (($item->_type ?? 'content') === 'task')
                                    <div
                                        class="cal-event task-event"
                                        title="{{ $item->title }} ({{ ucfirst($item->type ?? 'task') }})"
                                    >
                                        <i
                                            class="fas fa-{{ ($item->type ?? '') === 'shoot' ? 'camera' : (($item->type ?? '') === 'editing' ? 'film' : 'check-square') }} text-[8px] mr-0.5 opacity-70"
                                        ></i
                                        >{{ Str::limit($item->title, 12) }}
                                    </div>
                                @else
                                    @php
                                        $_platforms = \App\Support\ContentTags::normalize($item->platform, 'platform');
                                        $_primary = \App\Support\ContentTags::primary($_platforms, 'platform');
                                        $_types = \App\Support\ContentTags::normalize($item->type, 'type');
                                        $_primaryType = \App\Support\ContentTags::primary($_types, 'type');
                                        $isClientSubmitted = !empty($item->submitted_by_client_id);
                                    @endphp
                                    @php
                                        $workflowStage = null;
                                        $approvalStatus = null;
                                        if ($item->_workflows && $item->_workflows->count() > 0) {
                                            $workflowStage = $item->_workflows->first()->stage;
                                        }
                                        if ($item->_approvals && $item->_approvals->count() > 0) {
                                            $latestApproval = $item->_approvals->sortByDesc('created_at')->first();
                                            $approvalStatus = $latestApproval->status;
                                        }
                                    @endphp
                                    <div
                                        class="cal-event {{ $_primary }} type-{{ $_primaryType }} {{ $isClientSubmitted ? 'client-submitted' : '' }}"
                                        wire:click.stop="editContent({{ $item->id }})"
                                        title="{{ $item->title }} ({{ \App\Support\ContentTags::label($_types, 'type') }} | {{ \App\Support\ContentTags::label($_platforms, 'platform') }}){{ $isClientSubmitted ? ' • Client Request' : '' }}{{ $workflowStage ? ' | Stage: ' . $workflowStage : '' }}{{ $approvalStatus ? ' | Approval: ' . $approvalStatus : '' }}"
                                    >
                                        @if ($isClientSubmitted)
                                            <svg class="w-2.5 h-2.5 inline-block mr-0.5" fill="currentColor" viewBox="0 0 20 20">
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                        @endif
                                        {{ Str::limit($item->title, 14) }}
                                        @if ($workflowStage)
                                            <span
                                                class="absolute -top-1 -right-1 w-2 h-2 rounded-full {{ $workflowStage === 'published' ? 'bg-green-500' : ($workflowStage === 'review' ? 'bg-yellow-500' : 'bg-blue-500') }}"
                                            ></span>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                            @if (count($dayContent) > 3)
                                <div class="text-[9px] font-medium text-gray-400 pl-1">
                                    +{{ count($dayContent) - 3 }} more
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ========== LIST VIEW ========== --}}
    @if ($viewMode === 'list')
        @php $listContent = $this->getListContent(); @endphp
        <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Title</th>
                            <th>Client</th>
                            <th>Platform</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Workflow</th>
                            <th>Approval</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($listContent as $item)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <span
                                        class="text-gray-600"
                                        >{{ \App\Support\NepaliDate::display($item->date) }}</span
                                    >
                                </td>
                                <td>
                                    <span class="font-semibold text-gray-900">{{ $item->title }}</span>
                                    @php
                                        $hasContentAttachments = false;
                                        if ($item->attachments) {
                                            $contentAtts = is_string($item->attachments) ? json_decode($item->attachments, true) : $item->attachments;
                                            $hasContentAttachments = is_array($contentAtts) && count($contentAtts) > 0;
                                        }
                                    @endphp
                                    @if ($hasContentAttachments)
                                        <i
                                            class="fas fa-paperclip text-xs text-gray-400 ml-1"
                                            title="{{ count($contentAtts) }} attachment(s)"
                                        ></i>
                                    @endif
                                </td>
                                <td>
                                    <span class="text-gray-600">{{ $item->client_name ?? '-' }}</span>
                                </td>
                                <td>
                                    @php
                                        $_listPlatforms = \App\Support\ContentTags::normalize($item->platform, 'platform');
                                        $_listTypes = \App\Support\ContentTags::normalize($item->type, 'type');
                                    @endphp
                                    @if (in_array(\App\Support\ContentTags::ALL, $_listPlatforms, true))
                                        <span class="badge bg-[var(--brand)] text-white">All</span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @foreach (array_slice($_listPlatforms, 0, 2) as $_p)
                                                <span class="badge badge-{{ $_p }}">{{ ucfirst($_p) }}</span>
                                            @endforeach
                                            @if (count($_listPlatforms) > 2)
                                                <span class="badge bg-gray-100 text-gray-600"
                                                    >+{{ count($_listPlatforms) - 2 }}</span
                                                >
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if (in_array(\App\Support\ContentTags::ALL, $_listTypes, true))
                                        <span class="badge bg-[var(--brand)] text-white">All</span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @foreach (array_slice($_listTypes, 0, 2) as $_t)
                                                <span class="badge badge-{{ $_t }}">{{ ucfirst($_t) }}</span>
                                            @endforeach
                                            @if (count($_listTypes) > 2)
                                                <span class="badge bg-gray-100 text-gray-600"
                                                    >+{{ count($_listTypes) - 2 }}</span
                                                >
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span
                                        class="badge badge-{{ $item->status }}"
                                        >{{ str_replace('-', ' ', ucfirst($item->status)) }}</span
                                    >
                                </td>
                                <td>
                                    @if ($item->_workflows && $item->_workflows->count() > 0)
                                        @php $wf = $item->_workflows->first(); @endphp
                                        <span class="inline-flex items-center gap-1 text-xs">
                                            <span
                                                class="w-2 h-2 rounded-full {{ $wf->stage === 'published' ? 'bg-green-500' : ($wf->stage === 'review' ? 'bg-yellow-500' : 'bg-blue-500') }}"
                                            ></span>
                                            {{ ucfirst(str_replace('-', ' ', $wf->stage)) }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($item->_approvals && $item->_approvals->count() > 0)
                                        @php $appr = $item->_approvals->sortByDesc('created_at')->first(); @endphp
                                        <span
                                            class="badge badge-{{ $appr->status === 'approved' ? 'success' : ($appr->status === 'rejected' ? 'danger' : 'warning') }}"
                                        >
                                            {{ ucfirst($appr->status) }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @if (in_array($item->status, ['draft', 'scripting', 'revision']))
                                            @php
                                                $btnClass = $item->status === 'draft' ? 'btn-ghost' : 'btn-primary';
                                                $btnIcon = match($item->status) { 'draft' => 'fa-arrow-right', 'scripting' => 'fa-paper-plane', 'revision' => 'fa-redo', default => 'fa-arrow-right' };
                                                $btnLabel = match($item->status) { 'draft' => 'Script', 'scripting' => 'Submit', 'revision' => 'Resubmit', default => '' };
                                            @endphp

                                            @if ($item->status === 'scripting')
                                                {{-- Dropdown for scripting status: Submit or Skip to Workflow --}}
                                                <div class="relative inline-block" x-data="{ open: false }">
                                                    <button
                                                        @click="open = !open"
                                                        @click.away="open = false"
                                                        class="btn btn-sm {{ $btnClass }} py-1 px-2 text-xs flex items-center gap-1"
                                                    >
                                                        <i class="fas {{ $btnIcon }}"></i>
                                                        <span>{{ $btnLabel }}</span>
                                                        <i class="fas fa-chevron-down text-[9px]"></i>
                                                    </button>
                                                    <div
                                                        x-show="open"
                                                        x-transition
                                                        class="absolute right-0 mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-50"
                                                    >
                                                        <button
                                                            wire:click="submitForApproval({{ $item->id }})"
                                                            @click="open = false"
                                                            class="w-full text-left px-3 py-2 text-xs hover:bg-gray-50 rounded-t-lg flex items-center gap-2"
                                                        >
                                                            <i class="fas fa-check-circle text-green-600"></i>
                                                            <span>Submit for Approval</span>
                                                        </button>
                                                        <button
                                                            wire:click="sendToWorkflowDirectly({{ $item->id }})"
                                                            @click="open = false"
                                                            class="w-full text-left px-3 py-2 text-xs hover:bg-gray-50 rounded-b-lg flex items-center gap-2 border-t border-gray-100"
                                                        >
                                                            <i class="fas fa-bolt text-amber-600"></i>
                                                            <span>Skip to Workflow</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            @else
                                                {{-- Regular button for draft and revision --}}
                                                <button
                                                    wire:click="submitForApproval({{ $item->id }})"
                                                    class="btn btn-sm {{ $btnClass }} py-1 px-2 text-xs"
                                                >
                                                    <i class="fas {{ $btnIcon }} mr-1"></i> {{ $btnLabel }}
                                                </button>
                                            @endif
                                        @endif
                                        @if ($item->status === 'in-review')
                                            <span class="text-xs text-amber-600 font-medium"
                                                ><i class="fas fa-clock mr-1"></i>Pending</span
                                            >
                                        @endif
                                        @if ($item->status !== 'published' && $item->status !== 'in-review' && $item->status !== 'scheduled')
                                            <button
                                                wire:click="editContent({{ $item->id }})"
                                                class="btn btn-icon btn-ghost"
                                                title="Edit"
                                            >
                                                <i class="fas fa-pen text-gray-400 hover:text-[var(--brand)]"></i>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="$dispatch('open-confirm', { title: 'Delete Content?', message: 'Are you sure you want to delete this content? This cannot be undone.', type: 'danger', action: 'deleteContent', params: [{{ $item->id }}] })"
                                                class="btn btn-icon btn-ghost"
                                                aria-label="Delete content"
                                                title="Delete"
                                            >
                                                <i class="fas fa-trash text-gray-400 hover:text-red-500"></i>
                                            </button>
                                        @else
                                            <span class="text-xs text-green-600 font-medium"
                                                ><i class="fas fa-lock mr-1"></i>{{ ucfirst($item->status) }}</span
                                            >
                                            @if (in_array(Auth::user()->role, ['super-admin', 'admin'], true))
                                                <button
                                                    type="button"
                                                    wire:click="$dispatch('open-confirm', { title: 'Delete {{ ucfirst($item->status) }} Content?', message: 'Admin override — this item will be moved to trash.', type: 'danger', action: 'deleteContent', params: [{{ $item->id }}] })"
                                                    class="btn btn-icon btn-ghost"
                                                    aria-label="Delete content (admin)"
                                                    title="Delete (admin only)"
                                                >
                                                    <i class="fas fa-trash text-gray-400 hover:text-red-500"></i>
                                                </button>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="py-16 text-center">
                                        <div
                                            class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"
                                        >
                                            <i class="fas fa-calendar-alt text-2xl text-gray-300"></i>
                                        </div>
                                        <p class="text-gray-500 font-medium text-sm">No content found</p>
                                        <p class="text-gray-400 text-xs mt-1">Try adjusting your filters or add new content</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ========== CHARTS ========== --}}
    @php
        $platformData = $this->getPlatformData();
        $typeData = $this->getTypeData();
    @endphp
    <div
        x-data="{
        platformData: @js($platformData),
        typeData: @js($typeData),
        init() {
            this.$nextTick(() => this.renderCharts());
            $wire.on('contentUpdated', () => {
                this.platformData = @js($platformData);
                this.typeData = @js($typeData);
                this.$nextTick(() => this.renderCharts());
            });
            Livewire.on('contentUpdated', () => {
                this.platformData = @js($platformData);
                this.typeData = @js($typeData);
                this.$nextTick(() => this.renderCharts());
            });
        },
        renderCharts() {
            const brand = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#4f46e5';
            const platformColors = { instagram: '#E1306C', facebook: '#1877F2', tiktok: '#000000', youtube: '#FF0000', twitter: '#1DA1F2', linkedin: '#0A66C2' };
            const typeColors = { reel: '#8b5cf6', post: '#3b82f6', story: '#ec4899', video: '#ef4444', carousel: '#f59e0b', blog: '#22c55e' };

            const existingPlatform = Chart.getChart('platformDistChart');
            if (existingPlatform) existingPlatform.destroy();
            const existingType = Chart.getChart('typeMixChart');
            if (existingType) existingType.destroy();

            const pCtx = document.getElementById('platformDistChart');
            if (pCtx && Object.keys(this.platformData).length > 0) {
                const labels = Object.keys(this.platformData).map((k) => k.charAt(0).toUpperCase() + k.slice(1));
                const data = Object.values(this.platformData);
                const colors = Object.keys(this.platformData).map((k) => platformColors[k] || brand);
                new Chart(pCtx, {
                    type: 'bar',
                    data: { labels, datasets: [{ label: 'Content', data, backgroundColor: colors, borderRadius: 6, barThickness: 28 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } }, x: { grid: { display: false } } } }
                });
            }

            const tCtx = document.getElementById('typeMixChart');
            if (tCtx && Object.keys(this.typeData).length > 0) {
                const labels = Object.keys(this.typeData).map((k) => k.charAt(0).toUpperCase() + k.slice(1));
                const data = Object.values(this.typeData);
                const colors = Object.keys(this.typeData).map((k) => typeColors[k] || brand);
                new Chart(tCtx, {
                    type: 'doughnut',
                    data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0 }] },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'right', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } } } }
                });
            }
        }
    }"
        class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6"
    >
        <div class="bg-white rounded-2xl border border-gray-100 p-5">
            <h3 class="text-sm font-bold text-gray-900 mb-4">Platform Distribution</h3>
            <div style="height: 220px"><canvas id="platformDistChart"></canvas></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 p-5">
            <h3 class="text-sm font-bold text-gray-900 mb-4">Content Type Mix</h3>
            <div style="height: 220px"><canvas id="typeMixChart"></canvas></div>
        </div>
    </div>

    {{-- ========== CONTENT FORM MODAL ========== --}}
    @if ($showForm)
        <div class="modal-overlay" x-data x-on:keydown.escape.window="$wire.set('showForm', false)">
            <div class="modal-box max-w-2xl" x-on:click.stop>
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900">
                        <i class="fas fa-{{ $editingId ? 'pen' : 'plus' }} text-[var(--brand)] mr-2"></i>
                        {{ $editingId ? 'Edit Content' : 'Create Content' }}
                    </h3>
                    <button
                        wire:click="$set('showForm', false)"
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
                                    wire:model="title"
                                    class="form-input"
                                    placeholder="Enter content title"
                                />
                                @error ('title')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
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
                                @if ($this->editingId && count($formAttachments) > 0)
                                    <div class="mt-2">
                                        @include ('livewire.partials.attachment-display', ['attachments' => $formAttachments, 'label' => ''])
                                    </div>
                                @endif
                            </div>

                            {{-- All Linked Attachments (workflow + tasks) --}}
                            @if ($this->editingId)
                                @php $allLinkedAtts = $this->getDiscussionAttachments(); @endphp
                                @if (count($allLinkedAtts) > 0)
                                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-3">
                                        <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-2">
                                            <i class="fas fa-link text-gray-400 mr-1"></i> All Linked Attachments
                                        </p>
                                        @include ('livewire.partials.attachment-display', ['attachments' => $allLinkedAtts, 'label' => ''])
                                    </div>
                                @endif
                            @endif

                            {{-- Row: Client + Date --}}
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Client</label>
                                    <select wire:model="formClientId" class="form-select">
                                        <option value="">Internal / Own Company</option>
                                        @foreach ($clients as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Date <span class="text-red-500">*</span></label>
                                    <x-date-input model="formDate" name="formDate" />
                                    @error ('formDate')
                                        <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            {{-- Row: Due Date + Status --}}
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label"
                                        >Due Date <span class="text-gray-400 text-xs">(optional)</span></label
                                    >
                                    <x-date-input model="formDueDate" name="formDueDate" />
                                </div>
                                <div>
                                    <label class="form-label">Status <span class="text-red-500">*</span></label>
                                    <select wire:model="formStatus" class="form-select">
                                        @foreach (self::STATUSES as $s)
                                            <option value="{{ $s }}">{{ str_replace('-', ' ', ucfirst($s)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Assignees --}}
                            <div>
                                <label class="form-label">Assignees</label>
                                @include ('livewire.partials.assignee-select', ['usersJson' => json_encode($this->getUserList()), 'livewireProp' => 'formAssigneeIds'])
                            </div>

                            {{-- Reference File --}}
                            <div>
                                <label class="form-label"
                                    >Reference File
                                    <span class="text-gray-400 font-normal text-xs">(optional URL/name)</span></label
                                >
                                <input
                                    type="text"
                                    wire:model="referenceFile"
                                    class="form-input"
                                    placeholder="File name or URL"
                                />
                            </div>

                            {{-- Platforms Multi-Select --}}
                            @php $isAllPlatforms = in_array(\App\Support\ContentTags::ALL, $formPlatforms, true); @endphp
                            <div>
                                <label class="form-label">Platforms <span class="text-red-500">*</span></label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        wire:click="toggleAllPlatforms"
                                        class="inline-flex items-center gap-2 rounded-lg border {{ $isAllPlatforms ? 'border-[var(--brand)] bg-[var(--brand)] text-white' : 'border-gray-200 bg-white text-gray-700' }} px-3 py-2 cursor-pointer transition-colors hover:border-gray-300"
                                    >
                                        <i
                                            class="fas {{ $isAllPlatforms ? 'fa-check-square' : 'fa-square' }} text-[11px]"
                                        ></i>
                                        <span class="text-[11px] font-semibold uppercase tracking-wide">All</span>
                                    </button>
                                    @foreach (self::PLATFORMS as $p)
                                        <label
                                            class="inline-flex items-center gap-2 rounded-lg border {{ $isAllPlatforms || in_array($p, $formPlatforms) ? 'border-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : 'border-gray-200 bg-white' }} px-3 py-2 cursor-pointer transition-colors hover:border-gray-300 {{ $isAllPlatforms ? 'opacity-70' : '' }}"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:model="formPlatforms"
                                                value="{{ $p }}"
                                                @checked ($isAllPlatforms)
                                                class="rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]"
                                            />
                                            <span class="badge badge-{{ $p }} text-[10px]">{{ ucfirst($p) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if ($isAllPlatforms)
                                    <p class="text-[11px] text-gray-500 mt-1">"All" is selected — includes any future platforms too.</p>
                                @endif
                                @error ('formPlatforms')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Types Multi-Select --}}
                            @php $isAllTypes = in_array(\App\Support\ContentTags::ALL, $formTypes, true); @endphp
                            <div>
                                <label class="form-label">Content Types <span class="text-red-500">*</span></label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        wire:click="toggleAllTypes"
                                        class="inline-flex items-center gap-2 rounded-lg border {{ $isAllTypes ? 'border-[var(--brand)] bg-[var(--brand)] text-white' : 'border-gray-200 bg-white text-gray-700' }} px-3 py-2 cursor-pointer transition-colors hover:border-gray-300"
                                    >
                                        <i
                                            class="fas {{ $isAllTypes ? 'fa-check-square' : 'fa-square' }} text-[11px]"
                                        ></i>
                                        <span class="text-[11px] font-semibold uppercase tracking-wide">All</span>
                                    </button>
                                    @foreach (self::TYPES as $t)
                                        <label
                                            class="inline-flex items-center gap-2 rounded-lg border {{ $isAllTypes || in_array($t, $formTypes) ? 'border-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : 'border-gray-200 bg-white' }} px-3 py-2 cursor-pointer transition-colors hover:border-gray-300 {{ $isAllTypes ? 'opacity-70' : '' }}"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:model="formTypes"
                                                value="{{ $t }}"
                                                @checked ($isAllTypes)
                                                class="rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]"
                                            />
                                            <span class="badge badge-{{ $t }} text-[10px]">{{ ucfirst($t) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if ($isAllTypes)
                                    <p class="text-[11px] text-gray-500 mt-1">"All" is selected — includes any future types too.</p>
                                @endif
                                @error ('formTypes')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Row: Caption + Hashtags --}}
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Caption</label>
                                    <textarea
                                        wire:model="caption"
                                        class="form-textarea"
                                        rows="3"
                                        placeholder="Write your caption..."
                                    ></textarea>
                                </div>
                                <div>
                                    <label class="form-label">Hashtags</label>
                                    <textarea
                                        wire:model="hashtags"
                                        class="form-textarea"
                                        rows="3"
                                        placeholder="#hashtag1 #hashtag2"
                                    ></textarea>
                                </div>
                            </div>

                            @if (!$editingId && count($formPlatforms) > 0 && count($formTypes) > 0)
                                <div
                                    class="rounded-lg bg-blue-50 border border-blue-100 px-4 py-2.5 text-xs text-blue-700"
                                >
                                    <i class="fas fa-info-circle mr-1"></i>
                                    This will create <strong>1</strong> content item covering
                                    <strong>{{ \App\Support\ContentTags::label($formPlatforms, 'platform') }}</strong> ×
                                    <strong>{{ \App\Support\ContentTags::label($formTypes, 'type') }}</strong>.
                                </div>
                            @endif

                            {{-- Create Workflow Immediately Option (only for new content) --}}
                            @if (!$editingId)
                                <div class="rounded-lg border-2 border-dashed border-gray-200 bg-gray-50/50 px-4 py-3">
                                    <label class="flex items-start gap-3 cursor-pointer group">
                                        <input
                                            type="checkbox"
                                            wire:model.live="skipApproval"
                                            class="mt-0.5 rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]"
                                        />
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-semibold text-gray-900"
                                                    >Create Workflow Immediately</span
                                                >
                                                <span
                                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold uppercase tracking-wide"
                                                >
                                                    <i class="fas fa-bolt text-[8px]"></i>
                                                    Skip Approval
                                                </span>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1">When checked, content will be created in Content Planner AND a workflow item will be created automatically, bypassing the approval process.</p>
                                            <p class="text-xs text-gray-400 mt-1">
                                                <i class="fas fa-info-circle mr-1"></i>
                                                Useful for internal content or when approval is not required.
                                            </p>
                                        </div>
                                    </label>

                                    @if ($skipApproval)
                                        <div class="mt-4 pt-4 border-t border-gray-200/80">
                                            <label
                                                class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-2.5 flex items-center justify-between"
                                            >
                                                <span class="flex items-center gap-1.5">
                                                    <i class="fas fa-flag text-gray-400 text-xs"></i> Workflow Priority
                                                    <span class="text-red-500">*</span>
                                                </span>
                                                <span class="text-[11px] font-normal text-gray-400 capitalize"
                                                    >Selected:
                                                    <strong
                                                        class="text-gray-800 font-semibold"
                                                        >{{ $workflowPriority }}</strong
                                                    ></span
                                                >
                                            </label>

                                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                {{-- Low --}}
                                                <label
                                                    class="relative flex flex-col p-3 rounded-xl border-2 cursor-pointer transition-all duration-150 select-none group {{ $workflowPriority === 'low' ? 'border-slate-400 bg-slate-50 shadow-sm ring-2 ring-slate-400/20' : 'border-gray-200 hover:border-slate-300 hover:bg-slate-50/50' }}"
                                                >
                                                    <input
                                                        type="radio"
                                                        wire:model.live="workflowPriority"
                                                        value="low"
                                                        class="sr-only"
                                                    />
                                                    <div class="flex items-center justify-between w-full mb-1.5">
                                                        <span
                                                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-slate-100 text-slate-600 group-hover:scale-105 transition-transform"
                                                        >
                                                            <i class="fas fa-arrow-down text-xs"></i>
                                                        </span>
                                                        <span
                                                            class="w-4 h-4 rounded-full border border-slate-300 flex items-center justify-center {{ $workflowPriority === 'low' ? 'bg-slate-600 border-slate-600' : '' }}"
                                                        >
                                                            @if ($workflowPriority === 'low')
                                                                <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                                            @endif
                                                        </span>
                                                    </div>
                                                    <span class="text-xs font-bold text-gray-900">Low</span>
                                                    <span class="text-[10px] text-gray-500 mt-0.5 leading-tight"
                                                        >Standard line</span
                                                    >
                                                </label>

                                                {{-- Medium --}}
                                                <label
                                                    class="relative flex flex-col p-3 rounded-xl border-2 cursor-pointer transition-all duration-150 select-none group {{ $workflowPriority === 'medium' ? 'border-blue-500 bg-blue-50/90 shadow-sm ring-2 ring-blue-500/20' : 'border-gray-200 hover:border-blue-300 hover:bg-blue-50/40' }}"
                                                >
                                                    <input
                                                        type="radio"
                                                        wire:model.live="workflowPriority"
                                                        value="medium"
                                                        class="sr-only"
                                                    />
                                                    <div class="flex items-center justify-between w-full mb-1.5">
                                                        <span
                                                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-blue-100 text-blue-600 group-hover:scale-105 transition-transform"
                                                        >
                                                            <i class="fas fa-minus text-xs"></i>
                                                        </span>
                                                        <span
                                                            class="w-4 h-4 rounded-full border border-blue-300 flex items-center justify-center {{ $workflowPriority === 'medium' ? 'bg-blue-600 border-blue-600' : '' }}"
                                                        >
                                                            @if ($workflowPriority === 'medium')
                                                                <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                                            @endif
                                                        </span>
                                                    </div>
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-xs font-bold text-gray-900">Medium</span>
                                                        <span
                                                            class="text-[9px] font-bold text-blue-600 bg-blue-100 px-1.5 py-0.2 rounded-full"
                                                            >Default</span
                                                        >
                                                    </div>
                                                    <span class="text-[10px] text-gray-500 mt-0.5 leading-tight"
                                                        >Normal queue</span
                                                    >
                                                </label>

                                                {{-- High --}}
                                                <label
                                                    class="relative flex flex-col p-3 rounded-xl border-2 cursor-pointer transition-all duration-150 select-none group {{ $workflowPriority === 'high' ? 'border-amber-500 bg-amber-50/90 shadow-sm ring-2 ring-amber-500/20' : 'border-gray-200 hover:border-amber-300 hover:bg-amber-50/40' }}"
                                                >
                                                    <input
                                                        type="radio"
                                                        wire:model.live="workflowPriority"
                                                        value="high"
                                                        class="sr-only"
                                                    />
                                                    <div class="flex items-center justify-between w-full mb-1.5">
                                                        <span
                                                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-amber-100 text-amber-600 group-hover:scale-105 transition-transform"
                                                        >
                                                            <i class="fas fa-arrow-up text-xs"></i>
                                                        </span>
                                                        <span
                                                            class="w-4 h-4 rounded-full border border-amber-300 flex items-center justify-center {{ $workflowPriority === 'high' ? 'bg-amber-600 border-amber-600' : '' }}"
                                                        >
                                                            @if ($workflowPriority === 'high')
                                                                <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                                            @endif
                                                        </span>
                                                    </div>
                                                    <span class="text-xs font-bold text-gray-900">High</span>
                                                    <span class="text-[10px] text-gray-500 mt-0.5 leading-tight"
                                                        >Fast track</span
                                                    >
                                                </label>

                                                {{-- Urgent --}}
                                                <label
                                                    class="relative flex flex-col p-3 rounded-xl border-2 cursor-pointer transition-all duration-150 select-none group {{ $workflowPriority === 'urgent' ? 'border-red-500 bg-red-50/90 shadow-sm ring-2 ring-red-500/20' : 'border-gray-200 hover:border-red-300 hover:bg-red-50/40' }}"
                                                >
                                                    <input
                                                        type="radio"
                                                        wire:model.live="workflowPriority"
                                                        value="urgent"
                                                        class="sr-only"
                                                    />
                                                    <div class="flex items-center justify-between w-full mb-1.5">
                                                        <span
                                                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-red-100 text-red-600 group-hover:scale-105 transition-transform"
                                                        >
                                                            <i class="fas fa-bolt text-xs animate-pulse"></i>
                                                        </span>
                                                        <span
                                                            class="w-4 h-4 rounded-full border border-red-300 flex items-center justify-center {{ $workflowPriority === 'urgent' ? 'bg-red-600 border-red-600' : '' }}"
                                                        >
                                                            @if ($workflowPriority === 'urgent')
                                                                <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                                            @endif
                                                        </span>
                                                    </div>
                                                    <span class="text-xs font-bold text-gray-900">Urgent</span>
                                                    <span class="text-[10px] text-gray-500 mt-0.5 leading-tight"
                                                        >Immediate</span
                                                    >
                                                </label>
                                            </div>
                                            @error ('workflowPriority')
                                                <p class="text-xs text-red-500 mt-2 flex items-center gap-1">
                                                    <i class="fas fa-exclamation-circle text-[10px]"></i> {{ $message }}
                                                </p>
                                            @enderror
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Actions (fixed at bottom) --}}
                        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4 mt-4 shrink-0">
                            <button type="button" wire:click="$set('showForm', false)" class="btn btn-secondary">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                                <i class="fas fa-save text-xs"></i>
                                <span
                                    wire:loading.remove
                                    wire:target="save"
                                    >{{ $editingId ? 'Update Content' : 'Create Content' }}</span
                                >
                                <span wire:loading wire:target="save"><span class="spinner"></span> Saving...</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($showDayDetail)
        @php
        $detailDate = $selectedDate ? \Carbon\Carbon::parse($selectedDate) : null;
        $detailItems = $this->getDayContent();
        $detailContentCount = count(array_filter($detailItems, fn($i) => ($i->_type ?? 'content') === 'content'));
        $detailTaskCount = count(array_filter($detailItems, fn($i) => ($i->_type ?? 'content') === 'task'));
        $platformSummary = $this->getDayPlatformSummary();
        $contentLinks = $this->getContentLinks();

        $statusColors = [
            'draft' => 'bg-gray-100 text-gray-600',
            'scripting' => 'bg-purple-50 text-purple-700',
            'in-review' => 'bg-amber-50 text-amber-700',
            'revision' => 'bg-orange-50 text-orange-700',
            'scheduled' => 'bg-blue-50 text-blue-700',
            'published' => 'bg-emerald-50 text-emerald-700',
        ];
        $platformIcons = [
            'instagram' => 'camera',
            'facebook' => 'globe',
            'tiktok' => 'music',
            'youtube' => 'play',
        ];
        $platformBadge = [
            'instagram' => 'bg-pink-50 text-pink-600',
            'facebook' => 'bg-blue-50 text-blue-600',
            'tiktok' => 'bg-gray-100 text-gray-800',
            'youtube' => 'bg-red-50 text-red-600',
        ];
        @endphp
        <div
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm"
            wire:click.self="$set('showDayDetail', false)"
            x-data
            x-on:keydown.escape.window="$wire.set('showDayDetail', false)"
        >
            <div
                class="relative bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:max-w-lg max-h-[85vh] flex flex-col mx-0 sm:mx-4 overflow-hidden"
            >
                {{-- Header --}}
                <div class="flex-shrink-0 px-6 py-4 border-b border-gray-100 bg-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-bold text-gray-900">
                                {{ $detailDate ? \App\Support\NepaliDate::display($detailDate) : '' }}
                            </h3>
                            <p class="text-xs text-gray-400 mt-0.5">
                                @if ($detailContentCount > 0)
                                    {{ $detailContentCount }} {{ Str::plural('content', $detailContentCount) }}
                                @endif
                                @if ($detailContentCount > 0 && $detailTaskCount > 0) · @endif
                                @if ($detailTaskCount > 0)
                                    {{ $detailTaskCount }} {{ Str::plural('task', $detailTaskCount) }}
                                @endif
                                @if ($detailContentCount === 0 && $detailTaskCount === 0)
                                    No items scheduled
                                @endif
                            </p>
                        </div>
                        <button
                            wire:click="$set('showDayDetail', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition"
                        >
                            <i class="fas fa-times text-sm"></i>
                        </button>
                    </div>
                    {{-- Platform pills --}}
                    @if (count($platformSummary) > 0)
                        <div class="flex flex-wrap gap-1.5 mt-3">
                            @foreach ($platformSummary as $platform => $count)
                                <span
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium {{ $platformBadge[$platform] ?? 'bg-gray-100 text-gray-600' }}"
                                >
                                    <i
                                        class="fas fa-{{ $platformIcons[$platform] ?? 'globe' }}"
                                        style="font-size: 9px"
                                    ></i>
                                    {{ ucfirst($platform) }} ({{ $count }})
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Content List --}}
                <div class="flex-1 overflow-y-auto">
                    @if (count($detailItems) === 0)
                        <div class="flex flex-col items-center justify-center py-16 text-center px-6">
                            <div class="w-14 h-14 rounded-full bg-gray-50 flex items-center justify-center mb-3">
                                <i class="fas fa-calendar-plus text-xl text-gray-300"></i>
                            </div>
                            <p class="text-sm font-medium text-gray-400">Nothing scheduled</p>
                            <p class="text-xs text-gray-300 mt-1">Add content or a task for this day</p>
                        </div>
                    @else
                        <div class="divide-y divide-gray-50">
                            @foreach ($detailItems as $item)
                                @if (($item->_type ?? 'content') === 'task')
                                    {{-- Task / Shoot item --}}
                                    @php
                                        $taskStatusColors = [
                                            'todo' => 'bg-gray-100 text-gray-600',
                                            'in-progress' => 'bg-blue-50 text-blue-700',
                                            'completed' => 'bg-emerald-50 text-emerald-700',
                                        ];
                                        $taskIcons = ['task' => 'check-square', 'shoot' => 'camera', 'editing' => 'film'];
                                    @endphp
                                    <div
                                        class="flex items-start gap-3 px-6 py-3.5 hover:bg-amber-50/30 cursor-pointer transition-colors"
                                    >
                                        <div
                                            class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0 mt-0.5"
                                        >
                                            <i
                                                class="fas fa-{{ $taskIcons[$item->type ?? 'task'] ?? 'check-square' }} text-amber-600 text-xs"
                                            ></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <h4 class="text-sm font-semibold text-gray-800 truncate">
                                                    {{ $item->title }}
                                                </h4>
                                                <span
                                                    class="flex-shrink-0 text-[10px] font-semibold px-1.5 py-0.5 rounded-md {{ $taskStatusColors[$item->status ?? 'todo'] ?? 'bg-gray-100 text-gray-600' }}"
                                                >
                                                    {{ ucwords(str_replace('-', ' ', $item->status ?? 'todo')) }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-1.5 mt-1 text-xs text-gray-400">
                                                @if ($item->client_name ?? null)
                                                    <span class="text-gray-500">{{ $item->client_name }}</span>
                                                    <span>·</span>
                                                @endif
                                                <span class="capitalize">{{ ucfirst($item->type ?? 'task') }}</span>
                                                @if ($item->assignee ?? null)
                                                    <span>·</span>
                                                    <span>Assigned</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0 ml-2">
                                            <span
                                                class="text-[11px] px-2 py-1 rounded-lg bg-amber-50 text-amber-600 font-medium inline-flex items-center gap-1"
                                            >
                                                <i class="fas fa-video text-[9px]"></i>Shoot
                                            </span>
                                        </div>
                                    </div>
                                @else
                                    {{-- Content item --}}
                                    @php
                                    $links = $contentLinks[$item->id] ?? null;
                                    $workflow = $links['workflow'] ?? null;
                                    $approval = $links['approval'] ?? null;
                                    $clientName = null;
                                    if ($item->client_id) {
                                        $c = \App\Models\Client::find($item->client_id);
                                        $clientName = $c?->name;
                                    }
                                    $_ddPlatforms = \App\Support\ContentTags::normalize($item->platform, 'platform');
                                    $_ddPrimary = \App\Support\ContentTags::primary($_ddPlatforms, 'platform');
                                    $_ddTypeLabel = \App\Support\ContentTags::label(\App\Support\ContentTags::normalize($item->type, 'type'), 'type');
                                @endphp
                                    <div
                                        class="flex items-start gap-3 px-6 py-3.5 hover:bg-gray-50/50 cursor-pointer transition-colors"
                                        wire:click="editContent({{ $item->id }})"
                                    >
                                        <div
                                            class="w-8 h-8 rounded-lg bg-{{ $_ddPrimary }}-500/10 flex items-center justify-center flex-shrink-0 mt-0.5"
                                        >
                                            <i
                                                class="fas fa-{{ $platformIcons[$_ddPrimary] ?? 'globe' }} text-{{ $_ddPrimary }}-500 text-xs"
                                            ></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <h4 class="text-sm font-semibold text-gray-800 truncate">
                                                    {{ $item->title }}
                                                </h4>
                                                <span
                                                    class="flex-shrink-0 text-[10px] font-semibold px-1.5 py-0.5 rounded-md {{ $statusColors[$item->status] ?? 'bg-gray-100 text-gray-600' }}"
                                                >
                                                    {{ str_replace('-', ' ', ucfirst($item->status)) }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-1.5 mt-1 text-xs text-gray-400">
                                                @if ($clientName)
                                                    <span class="text-gray-500">{{ $clientName }}</span>
                                                    <span>·</span>
                                                @endif
                                                <span>{{ $_ddTypeLabel }}</span>
                                                @if ($workflow)
                                                    <span>·</span>
                                                    <span
                                                        class="brand-text font-medium capitalize"
                                                        >{{ str_replace('-', ' ', $workflow->stage) }}</span
                                                    >
                                                @endif
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0 ml-2 flex items-center gap-1.5">
                                            <button
                                                wire:click.stop="openDiscussion({{ $item->id }})"
                                                class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition"
                                                title="Discussion"
                                            >
                                                <i class="fas fa-comments text-[11px]"></i>
                                            </button>
                                            @if (in_array($item->status, ['draft', 'scripting', 'revision']))
                                                @php
                                                $ddBtnIcon = match($item->status) { 'draft' => 'fa-arrow-right', 'scripting' => 'fa-paper-plane', 'revision' => 'fa-redo', default => 'fa-arrow-right' };
                                                $ddBtnLabel = match($item->status) { 'draft' => 'Script', 'scripting' => 'Submit', 'revision' => 'Resubmit', default => '' };
                                            @endphp
                                                @if ($item->status === 'scripting')
                                                    {{-- Dropdown for scripting: Submit or Skip to Workflow --}}
                                                    <div class="relative inline-block" x-data="{ open: false }">
                                                        <button
                                                            @click.stop="open = !open"
                                                            @click.away="open = false"
                                                            class="text-[11px] px-2.5 py-1 rounded-lg bg-[var(--brand)] text-white hover:opacity-90 font-medium transition flex items-center gap-1"
                                                        >
                                                            <i class="fas {{ $ddBtnIcon }} text-[9px]"></i>
                                                            <span>{{ $ddBtnLabel }}</span>
                                                            <i class="fas fa-chevron-down text-[8px]"></i>
                                                        </button>
                                                        <div
                                                            x-show="open"
                                                            x-transition
                                                            class="absolute right-0 mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-50"
                                                        >
                                                            <button
                                                                wire:click.stop="submitForApproval({{ $item->id }})"
                                                                @click="open = false"
                                                                class="w-full text-left px-3 py-2 text-xs hover:bg-gray-50 rounded-t-lg flex items-center gap-2"
                                                            >
                                                                <i class="fas fa-check-circle text-green-600"></i>
                                                                <span>Submit for Approval</span>
                                                            </button>
                                                            <button
                                                                wire:click.stop="sendToWorkflowDirectly({{ $item->id }})"
                                                                @click="open = false"
                                                                class="w-full text-left px-3 py-2 text-xs hover:bg-gray-50 rounded-b-lg flex items-center gap-2 border-t border-gray-100"
                                                            >
                                                                <i class="fas fa-bolt text-amber-600"></i>
                                                                <span>Skip to Workflow</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                @else
                                                    <button
                                                        wire:click.stop="submitForApproval({{ $item->id }})"
                                                        class="text-[11px] px-2.5 py-1 rounded-lg {{ $item->status === 'draft' ? 'bg-gray-100 text-gray-600 hover:bg-gray-200' : 'bg-[var(--brand)] text-white hover:opacity-90' }} font-medium transition"
                                                    >
                                                        <i class="fas {{ $ddBtnIcon }} mr-1 text-[9px]"></i
                                                        >{{ $ddBtnLabel }}
                                                    </button>
                                                @endif
                                            @elseif ($item->status === 'in-review')
                                                @if ($approval)
                                                    <span
                                                        class="text-[11px] px-2 py-1 rounded-lg bg-amber-50 text-amber-600 font-medium inline-flex items-center gap-1"
                                                    >
                                                        <i class="fas fa-clock text-[9px]"></i
                                                        >{{ ucfirst($approval->status) }}
                                                    </span>
                                                @else
                                                    <span
                                                        class="text-[11px] px-2 py-1 rounded-lg bg-amber-50 text-amber-600 font-medium inline-flex items-center gap-1"
                                                    >
                                                        <i class="fas fa-clock text-[9px]"></i>In Review
                                                    </span>
                                                @endif
                                            @elseif ($item->status === 'published')
                                                <span
                                                    class="text-[11px] px-2 py-1 rounded-lg bg-emerald-50 text-emerald-600 font-medium inline-flex items-center gap-1"
                                                >
                                                    <i class="fas fa-check text-[9px]"></i>Published
                                                </span>
                                            @endif
                                            @if (in_array($item->status, ['published', 'in-review', 'scheduled']) && in_array(Auth::user()->role, ['super-admin', 'admin'], true))
                                                <button
                                                    type="button"
                                                    wire:click.stop="$dispatch('open-confirm', { title: 'Delete Content?', message: 'Admin override — this item will be moved to trash.', type: 'danger', action: 'deleteContent', params: [{{ $item->id }}] })"
                                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 transition"
                                                    aria-label="Delete content (admin)"
                                                    title="Delete (admin only)"
                                                >
                                                    <i class="fas fa-trash text-[11px]"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Footer --}}
                <div
                    class="flex-shrink-0 px-6 py-3.5 border-t border-gray-100 bg-white flex items-center justify-between"
                >
                    <button wire:click="$set('showDayDetail', false)" class="btn btn-secondary text-sm">Close</button>
                    <button wire:click="openForm('{{ $selectedDate }}')" class="btn btn-primary text-sm">
                        <i class="fas fa-plus text-xs mr-1"></i> Add Content
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ========== CONTENT DISCUSSION MODAL ========== --}}
    @if ($showContentDiscussion && $selectedContentId)
        @php
            $discContent = \App\Models\Content::with('client')->find($selectedContentId);
            $discComments = $this->getContentDiscussionComments();
        @endphp
        @if ($discContent)
            @php
            $_discTypes = \App\Support\ContentTags::normalize($discContent->type, 'type');
            $_discPlatforms = \App\Support\ContentTags::normalize($discContent->platform, 'platform');
            $_discPrimaryType = \App\Support\ContentTags::primary($_discTypes, 'type');
            $calTypeBadge = match($_discPrimaryType) {
                'reel'     => 'bg-pink-50 text-pink-700 border-pink-200',
                'post'     => 'bg-blue-50 text-blue-700 border-blue-200',
                'story'    => 'bg-purple-50 text-purple-700 border-purple-200',
                'video'    => 'bg-red-50 text-red-700 border-red-200',
                'carousel' => 'bg-amber-50 text-amber-700 border-amber-200',
                'blog'     => 'bg-green-50 text-green-700 border-green-200',
                default    => 'bg-gray-50 text-gray-600 border-gray-200',
            };
            $calStatusBadge = match($discContent->status) {
                'published'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'draft'      => 'bg-gray-50 text-gray-600 border-gray-200',
                'scheduled'  => 'bg-blue-50 text-blue-700 border-blue-200',
                'in-review'  => 'bg-amber-50 text-amber-700 border-amber-200',
                default      => 'bg-gray-50 text-gray-600 border-gray-200',
            };
        @endphp
            <div
                class="modal-overlay z-50"
                wire:click.self="$set('showContentDiscussion', false)"
                x-on:keydown.escape.window="$wire.set('showContentDiscussion', false)"
            >
                <div class="modal-box max-w-2xl" x-on:click.stop>
                    {{-- Header --}}
                    <div class="modal-header border-b border-gray-100 pb-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5 mb-1.5">
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $calTypeBadge }}"
                                    >{{ \App\Support\ContentTags::label($_discTypes, 'type') }}</span
                                >
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $calStatusBadge }}"
                                    >{{ ucwords(str_replace('-', ' ', $discContent->status)) }}</span
                                >
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 leading-snug">{{ $discContent->title }}</h3>
                        </div>
                        <button
                            wire:click="$set('showContentDiscussion', false)"
                            class="btn btn-ghost btn-icon btn-sm"
                            aria-label="Close"
                        >
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div class="modal-body space-y-4 max-h-[70vh] overflow-y-auto">
                        {{-- Caption --}}
                        @if ($discContent->caption)
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Caption</p>
                                <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $discContent->caption }}</p>
                            </div>
                        @endif

                        {{-- Metadata --}}
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Client</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-building text-gray-400"></i> {{ $discContent->client->name ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Platform</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-share-nodes text-gray-400"></i> {{ \App\Support\ContentTags::label($_discPlatforms, 'platform') }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Date</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5">
                                    <i class="fas fa-calendar-alt text-gray-400"></i>
                                    {{ $discContent->date ? \App\Support\NepaliDate::display($discContent->date) : '—' }}
                                </p>
                            </div>
                            @if ($discContent->due_date)
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Due Date</p>
                                    <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-clock text-gray-400"></i> {{ \App\Support\NepaliDate::display($discContent->due_date) }}</p>
                                </div>
                            @endif
                            @if ($discContent->submitted_by_client_id)
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Submitted By</p>
                                    <p class="text-gray-800 text-xs flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                fill-rule="evenodd"
                                                d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                        <span class="font-semibold text-amber-600">Client Request</span>
                                        @if ($discContent->submittedByClient)
                                            <span class="text-gray-500"
                                                >({{ $discContent->submittedByClient->name }})</span
                                            >
                                        @endif
                                    </p>
                                </div>
                            @else
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Created By</p>
                                    <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-user text-gray-400"></i> {{ $discContent->creator->name ?? '—' }}</p>
                                </div>
                            @endif
                        </div>

                        {{-- Quick Actions --}}
                        <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100">
                            <button wire:click="editContent({{ $discContent->id }})" class="btn btn-secondary btn-sm">
                                <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                    />
                                </svg>
                                Edit
                            </button>

                            @php
                                $hasWorkflow = \App\Models\Workflow::where('content_id', $discContent->id)->whereNull('deleted_at')->exists();
                            @endphp

                            @if (!$hasWorkflow)
                                <button
                                    wire:click="openWorkflowForm({{ $discContent->id }})"
                                    class="btn btn-primary btn-sm"
                                >
                                    <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"
                                        />
                                    </svg>
                                    Add to Workflow
                                </button>
                            @else
                                <button
                                    wire:click="openPriorityForm({{ $discContent->id }})"
                                    class="btn btn-secondary btn-sm"
                                >
                                    <svg class="w-3.5 h-3.5 mr-1.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path
                                            d="M3 6a3 3 0 013-3h10a1 1 0 01.8 1.6L14.25 8l2.55 3.4A1 1 0 0116 13H6a1 1 0 00-1 1v3a1 1 0 11-2 0V6z"
                                        />
                                    </svg>
                                    Change Priority
                                </button>
                            @endif
                        </div>

                        {{-- Hashtags --}}
                        @if ($discContent->hashtags && trim($discContent->hashtags) !== '')
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1.5">Hashtags</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (explode(',', $discContent->hashtags) as $tag)
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
                        @php $cAtts = $this->getDiscussionAttachments(); @endphp
                        @if (count($cAtts) > 0)
                            <div>
                                @include ('livewire.partials.attachment-display', ['attachments' => $cAtts, 'label' => 'Attachments'])
                            </div>
                        @endif

                        {{-- Discussion --}}
                        <div class="border-t border-gray-100 pt-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fas fa-comments text-gray-400"></i> Discussion
                            </h4>

                            <div class="space-y-3 max-h-48 overflow-y-auto mb-3">
                                @forelse ($discComments as $comment)
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
                                <x-tiptap-editor wire="commentText" name="calComment" placeholder="Add a comment..." />
                                <div class="flex items-center justify-between mt-2">
                                    <x-file-picker
                                        :clientId="$discContent->client_id"
                                        wire="commentAttachments"
                                        :initial="$commentAttachments"
                                    />
                                    <button
                                        @click="$wire.addContentComment($wire.get('commentAttachments'))"
                                        wire:loading.attr="disabled"
                                        class="btn btn-primary btn-sm"
                                        x-data
                                    >
                                        <i
                                            class="fas fa-paper-plane text-xs"
                                            wire:loading.remove
                                            wire:target="addContentComment"
                                        ></i>
                                        <i
                                            class="fas fa-spinner fa-spin text-xs"
                                            wire:loading
                                            wire:target="addContentComment"
                                        ></i>
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

    {{-- Workflow Priority Modal --}}
    @if ($showWorkflowForm && $workflowContentId)
        <div class="modal-overlay z-[60]" x-data x-on:keydown.escape.window="$wire.set('showWorkflowForm', false)">
            <div
                class="modal-box max-w-lg overflow-hidden rounded-2xl shadow-2xl border border-gray-100"
                x-on:click.stop
            >
                <div class="modal-header px-6 py-5 flex items-center justify-between border-b border-gray-100 bg-white">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-10 h-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center font-bold shrink-0"
                        >
                            <i class="fas fa-bolt text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 leading-snug">Add to Workflow</h3>
                            <p class="text-xs text-gray-500 font-normal">Set initial priority level for this workflow task</p>
                        </div>
                    </div>
                    <button
                        wire:click="$set('showWorkflowForm', false)"
                        class="btn btn-ghost btn-icon btn-sm text-gray-400 hover:text-gray-600"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="modal-body p-6 space-y-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3 block">Select Priority Level</p>

                    <div class="grid grid-cols-1 gap-3">
                        {{-- Low Priority --}}
                        <label
                            class="flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 group select-none {{ $workflowPriority === 'low' ? 'border-slate-400 bg-slate-50 shadow-sm ring-2 ring-slate-400/20' : 'border-gray-200/90 hover:border-slate-300 hover:bg-slate-50/50' }}"
                        >
                            <div class="flex items-center gap-3.5">
                                <div
                                    class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform"
                                >
                                    <i class="fas fa-arrow-down text-sm"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">Low Priority</span>
                                        <span
                                            class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-[10px] font-bold uppercase tracking-wider"
                                            >Low</span
                                        >
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">Standard turnaround time, non-critical task</p>
                                </div>
                            </div>
                            <input
                                type="radio"
                                wire:model.live="workflowPriority"
                                value="low"
                                class="w-4 h-4 text-slate-600 focus:ring-slate-400"
                            />
                        </label>

                        {{-- Medium Priority --}}
                        <label
                            class="flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 group select-none {{ $workflowPriority === 'medium' ? 'border-blue-500 bg-blue-50/90 shadow-sm ring-2 ring-blue-500/20' : 'border-gray-200/90 hover:border-blue-300 hover:bg-blue-50/40' }}"
                        >
                            <div class="flex items-center gap-3.5">
                                <div
                                    class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform"
                                >
                                    <i class="fas fa-minus text-sm"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">Medium Priority</span>
                                        <span
                                            class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-bold uppercase tracking-wider"
                                            >Default</span
                                        >
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">Normal production queue & standard pipeline</p>
                                </div>
                            </div>
                            <input
                                type="radio"
                                wire:model.live="workflowPriority"
                                value="medium"
                                class="w-4 h-4 text-blue-600 focus:ring-blue-400"
                            />
                        </label>

                        {{-- High Priority --}}
                        <label
                            class="flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 group select-none {{ $workflowPriority === 'high' ? 'border-amber-500 bg-amber-50/90 shadow-sm ring-2 ring-amber-500/20' : 'border-gray-200/90 hover:border-amber-300 hover:bg-amber-50/40' }}"
                        >
                            <div class="flex items-center gap-3.5">
                                <div
                                    class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform"
                                >
                                    <i class="fas fa-arrow-up text-sm"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">High Priority</span>
                                        <span
                                            class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold uppercase tracking-wider"
                                            >Fast Track</span
                                        >
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">Expedited review and fast team assignment</p>
                                </div>
                            </div>
                            <input
                                type="radio"
                                wire:model.live="workflowPriority"
                                value="high"
                                class="w-4 h-4 text-amber-600 focus:ring-amber-400"
                            />
                        </label>

                        {{-- Urgent Priority --}}
                        <label
                            class="flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 group select-none {{ $workflowPriority === 'urgent' ? 'border-red-500 bg-red-50/90 shadow-sm ring-2 ring-red-500/20' : 'border-gray-200/90 hover:border-red-300 hover:bg-red-50/40' }}"
                        >
                            <div class="flex items-center gap-3.5">
                                <div
                                    class="w-10 h-10 rounded-xl bg-red-100 text-red-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform"
                                >
                                    <i class="fas fa-bolt text-sm animate-pulse"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">Urgent Priority</span>
                                        <span
                                            class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[10px] font-bold uppercase tracking-wider"
                                            >Immediate</span
                                        >
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">Requires immediate attention and action</p>
                                </div>
                            </div>
                            <input
                                type="radio"
                                wire:model.live="workflowPriority"
                                value="urgent"
                                class="w-4 h-4 text-red-600 focus:ring-red-400"
                            />
                        </label>
                    </div>
                </div>

                <div
                    class="modal-footer bg-gray-50/70 border-t border-gray-100 px-6 py-4 flex items-center justify-end gap-3 rounded-b-2xl"
                >
                    <button
                        wire:click="$set('showWorkflowForm', false)"
                        class="btn btn-secondary px-5 py-2.5 text-xs font-semibold rounded-xl"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="createWorkflowFromContent"
                        class="btn btn-primary px-5 py-2.5 text-xs font-semibold rounded-xl flex items-center gap-2"
                    >
                        <i class="fas fa-plus text-xs"></i>
                        <span>Create Workflow</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Priority Change Modal --}}
    @if ($showPriorityForm && $workflowContentId)
        <div class="modal-overlay z-[60]" x-data x-on:keydown.escape.window="$wire.set('showPriorityForm', false)">
            <div
                class="modal-box max-w-lg overflow-hidden rounded-2xl shadow-2xl border border-gray-100"
                x-on:click.stop
            >
                <div class="modal-header px-6 py-5 flex items-center justify-between border-b border-gray-100 bg-white">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-10 h-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center font-bold shrink-0"
                        >
                            <i class="fas fa-flag text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 leading-snug">Change Priority</h3>
                            <p class="text-xs text-gray-500 font-normal">Update priority level for this workflow task</p>
                        </div>
                    </div>
                    <button
                        wire:click="$set('showPriorityForm', false)"
                        class="btn btn-ghost btn-icon btn-sm text-gray-400 hover:text-gray-600"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="modal-body p-6 space-y-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3 block">Select New Priority Level</p>

                    <div class="grid grid-cols-1 gap-3">
                        {{-- Low Priority --}}
                        <label
                            class="flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 group select-none {{ $workflowPriority === 'low' ? 'border-slate-400 bg-slate-50 shadow-sm ring-2 ring-slate-400/20' : 'border-gray-200/90 hover:border-slate-300 hover:bg-slate-50/50' }}"
                        >
                            <div class="flex items-center gap-3.5">
                                <div
                                    class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform"
                                >
                                    <i class="fas fa-arrow-down text-sm"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">Low Priority</span>
                                        <span
                                            class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-[10px] font-bold uppercase tracking-wider"
                                            >Low</span
                                        >
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">Standard turnaround time, non-critical task</p>
                                </div>
                            </div>
                            <input
                                type="radio"
                                wire:model.live="workflowPriority"
                                value="low"
                                class="w-4 h-4 text-slate-600 focus:ring-slate-400"
                            />
                        </label>

                        {{-- Medium Priority --}}
                        <label
                            class="flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 group select-none {{ $workflowPriority === 'medium' ? 'border-blue-500 bg-blue-50/90 shadow-sm ring-2 ring-blue-500/20' : 'border-gray-200/90 hover:border-blue-300 hover:bg-blue-50/40' }}"
                        >
                            <div class="flex items-center gap-3.5">
                                <div
                                    class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform"
                                >
                                    <i class="fas fa-minus text-sm"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">Medium Priority</span>
                                        <span
                                            class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-bold uppercase tracking-wider"
                                            >Default</span
                                        >
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">Normal production queue & standard pipeline</p>
                                </div>
                            </div>
                            <input
                                type="radio"
                                wire:model.live="workflowPriority"
                                value="medium"
                                class="w-4 h-4 text-blue-600 focus:ring-blue-400"
                            />
                        </label>

                        {{-- High Priority --}}
                        <label
                            class="flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 group select-none {{ $workflowPriority === 'high' ? 'border-amber-500 bg-amber-50/90 shadow-sm ring-2 ring-amber-500/20' : 'border-gray-200/90 hover:border-amber-300 hover:bg-amber-50/40' }}"
                        >
                            <div class="flex items-center gap-3.5">
                                <div
                                    class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform"
                                >
                                    <i class="fas fa-arrow-up text-sm"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">High Priority</span>
                                        <span
                                            class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold uppercase tracking-wider"
                                            >Fast Track</span
                                        >
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">Expedited review and fast team assignment</p>
                                </div>
                            </div>
                            <input
                                type="radio"
                                wire:model.live="workflowPriority"
                                value="high"
                                class="w-4 h-4 text-amber-600 focus:ring-amber-400"
                            />
                        </label>

                        {{-- Urgent Priority --}}
                        <label
                            class="flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 group select-none {{ $workflowPriority === 'urgent' ? 'border-red-500 bg-red-50/90 shadow-sm ring-2 ring-red-500/20' : 'border-gray-200/90 hover:border-red-300 hover:bg-red-50/40' }}"
                        >
                            <div class="flex items-center gap-3.5">
                                <div
                                    class="w-10 h-10 rounded-xl bg-red-100 text-red-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform"
                                >
                                    <i class="fas fa-bolt text-sm animate-pulse"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">Urgent Priority</span>
                                        <span
                                            class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[10px] font-bold uppercase tracking-wider"
                                            >Immediate</span
                                        >
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">Requires immediate attention and action</p>
                                </div>
                            </div>
                            <input
                                type="radio"
                                wire:model.live="workflowPriority"
                                value="urgent"
                                class="w-4 h-4 text-red-600 focus:ring-red-400"
                            />
                        </label>
                    </div>
                </div>

                <div
                    class="modal-footer bg-gray-50/70 border-t border-gray-100 px-6 py-4 flex items-center justify-end gap-3 rounded-b-2xl"
                >
                    <button
                        wire:click="$set('showPriorityForm', false)"
                        class="btn btn-secondary px-5 py-2.5 text-xs font-semibold rounded-xl"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="updateContentPriority"
                        class="btn btn-primary px-5 py-2.5 text-xs font-semibold rounded-xl flex items-center gap-2"
                    >
                        <i class="fas fa-check text-xs"></i>
                        <span>Update Priority</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
