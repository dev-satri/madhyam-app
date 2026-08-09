<?php

use App\Models\Approval;
use App\Models\Comment;
use App\Models\File;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public string $statusFilter = '';

    public string $clientFilter = '';

    public string $stageFilter = '';

    public string $typeFilter = '';

    public string $search = '';

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

    public array $formAttachments = [];

    public string $formAttachmentsJson = '[]';

    public string $commentAttachments = '[]';

    public $newFileUpload = null;



    public int $detailId = 0;

    public bool $showDetail = false;

    public array $selectedItems = [];

    public bool $showReasonModal = false;

    public string $reasonAction = '';

    public array $reasonTargets = [];

    public string $reasonText = '';

    public function mount(): void {}

    public function updatedFormAttachmentsJson(string $value): void
    {
        $this->formAttachments = json_decode($value, true) ?: [];
    }

    public function getAvailableContent(): Collection
    {
        if (! $this->formClientId) {
            return collect();
        }

        return DB::table('contents')
            ->where('client_id', $this->formClientId)
            ->orderBy('date', 'desc')
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedClientFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStageFilter(): void
    {
        $this->resetPage();
    }

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
        return DB::table('clients')->whereNull('deleted_at')->where('status', 'active')->orderBy('name')->get();
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
        if (empty($ids)) {
            return [];
        }

        return DB::table('approval_comments')
            ->whereIn('approval_id', $ids)
            ->select('approval_id', DB::raw('count(*) as cnt'))
            ->groupBy('approval_id')
            ->pluck('cnt', 'approval_id')
            ->toArray();
    }

    public function isImage(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        return (bool) preg_match('/\.(jpe?g|png|gif|webp|svg|bmp|ico)$/i', $path);
    }

    public function fileUrl(?string $path): string
    {
        if (! $path) {
            return '';
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::url($path);
    }

    public function getFilteredApprovals()
    {
        // Approvals table (per plan §26 spec) has no content_id — title lives on the row.
        $q = DB::table('approvals')
            ->leftJoin('clients', 'approvals.client_id', '=', 'clients.id')
            ->leftJoin('users', 'approvals.submitted_by', '=', 'users.id')
            ->whereNull('approvals.deleted_at');

        if ($this->statusFilter) {
            $q->where('approvals.status', $this->statusFilter);
        }
        if ($this->clientFilter) {
            $q->where('approvals.client_id', $this->clientFilter);
        }
        if ($this->typeFilter) {
            $q->where('approvals.type', $this->typeFilter);
        }
        if ($this->stageFilter) {
            $q->where('approvals.approval_stage', $this->stageFilter);
        }
        if ($this->search) {
            $q->where(function ($q) {
                $q->where('approvals.title', 'like', "%{$this->search}%")
                    ->orWhere('users.name', 'like', "%{$this->search}%");
            });
        }

        $user = Auth::user() ?? Auth::guard('client')->user();
        if ($user && ! in_array($user->role ?? 'client', ['super-admin', 'admin', 'manager'])) {
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
        // Stats must mirror the visibility rules of getFilteredApprovals() —
        // clients only see counts for their own client_id; non-manager staff
        // only see their own submissions. Previously this leaked global
        // agency-wide totals into the client portal.
        $q = DB::table('approvals')->whereNull('deleted_at');

        if ($account = Auth::guard('client')->user()) {
            $q->where('client_id', $account->client_id);
        } else {
            $user = Auth::user();
            if ($user && ! in_array($user->role ?? '', ['super-admin', 'admin', 'manager'])) {
                $q->where('submitted_by', $user->id);
            }
        }

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
        $isClient = $actor && ($actor->role ?? 'client') === 'client';

        $approval = DB::table('approvals')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $approval) {
            return;
        }

        // Clients cannot reject
        if ($status === 'rejected' && $isClient) {
            $this->dispatch('toast', message: 'Clients cannot reject approvals', type: 'error');

            return;
        }

        // Sequential flow rules
        if ($approval->approval_stage === 'admin-pending' && $isClient) {
            $this->dispatch('toast', message: 'This approval is awaiting admin review', type: 'error');

            return;
        }

        if ($approval->approval_stage === 'client-pending' && ! $isClient) {
            // Admin can still approve/reject at client-pending stage
        }

        if ($approval->approval_stage === 'first' && $isClient) {
            $this->dispatch('toast', message: 'This is an admin-only approval', type: 'error');

            return;
        }

        $current = $approval->status;
        $allowed = ($current === 'pending') || ($current === 'revision' && in_array($status, ['approved', 'rejected']));
        if (! $allowed) {
            $this->dispatch('toast', message: 'Cannot change this approval status', type: 'warning');

            return;
        }

        $this->applyStatus($id, $status);
        $this->dispatch('toast', message: "Approval {$status}", type: $status === 'approved' ? 'success' : 'info');
        $this->resetPage();
    }

    /**
     * Find the workflow linked to an approval.
     * Tries content_id first, then falls back to title matching.
     */
    private function findLinkedWorkflow(object $approval): ?object
    {
        // Try by content_id first (most reliable)
        if ($approval->content_id) {
            $wf = DB::table('workflows')->where('content_id', $approval->content_id)->first();
            if ($wf) {
                return $wf;
            }
        }

        // Fallback: match by title (strip parenthetical suffixes like " (Instagram / Reel)")
        $baseTitle = preg_replace('/\s*\([^)]*\)\s*$/', '', $approval->title ?? '');
        if ($baseTitle) {
            $wf = DB::table('workflows')
                ->where('title', $baseTitle)
                ->orWhere('title', 'like', $baseTitle . '%')
                ->first();
            if ($wf) {
                return $wf;
            }
        }

        return null;
    }

    private function applyStatus(int $id, string $status, ?string $reason = null, bool $suppressSideEffects = false): bool
    {
        $actor = Auth::user() ?? Auth::guard('client')->user();
        $isClient = $actor && ($actor->role ?? 'client') === 'client';

        $approval = DB::table('approvals')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $approval) {
            return false;
        }

        $current = $approval->status;
        $allowed = ($current === 'pending') || ($current === 'revision' && in_array($status, ['approved', 'rejected']));
        if (! $allowed) {
            return false;
        }

        DB::table('approvals')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);

        $commentUserId = $actor instanceof User ? $actor?->id : null;
        if ($reason) {
            DB::table('approval_comments')->insert([
                'approval_id' => $id, 'user_id' => $commentUserId, 'user_name' => $actor?->name ?? 'Unknown',
                'text' => $reason, 'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('approval_comments')->insert([
            'approval_id' => $id, 'user_id' => $commentUserId, 'user_name' => $actor?->name ?? 'System',
            'text' => "Status changed to {$status}", 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ActivityLogger::class)->record($actor, "Approval #{$id} → {$status}");

        // ── SIDE EFFECTS based on approval_stage ──

        if ($approval->approval_stage === 'first') {
            // FIRST APPROVAL: Content submitted from planner
            if ($status === 'approved') {
                // Content stays as 'in-review' (it's now in the workflow pipeline)
                // Create workflow item in first stage
                $content = DB::table('contents')->where('id', $approval->content_id)->first();
                if ($content) {
                    $firstStage = DB::table('workflow_stages')->orderBy('order')->first();
                    DB::table('workflows')->insert([
                        'title' => $approval->title,
                        'client_id' => $approval->client_id,
                        'content_id' => $approval->content_id,
                        'type' => $approval->type,
                        'stage' => $firstStage->key ?? 'todo',
                        'deadline' => $content->due_date,
                        'submitted_by' => $actor?->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Track package usage (only for client content, not internal)
                    if ($approval->client_id) {
                        \App\Services\PackageService::recordWorkflow($approval->client_id);
                    }
                }

                if (! $suppressSideEffects) {
                    app(NotificationService::class)->notifyContentApproved($approval->title, $approval->client_id);
                }
            } elseif ($status === 'rejected') {
                DB::table('contents')->where('id', $approval->content_id)->update([
                    'status' => 'revision',
                    'updated_at' => now(),
                ]);
                if (! $suppressSideEffects) {
                    app(NotificationService::class)->notifyContentRejected($approval->title, $reason, $approval->client_id);
                }
            } elseif ($status === 'revision') {
                DB::table('contents')->where('id', $approval->content_id)->update([
                    'status' => 'revision',
                    'updated_at' => now(),
                ]);
                if (! $suppressSideEffects) {
                    app(NotificationService::class)->notifyContentRevision($approval->title, $reason, $approval->client_id);
                }
            }
        } elseif ($approval->approval_stage === 'admin-pending') {
            // ADMIN PENDING: Workflow review — admin approves first
            if ($status === 'approved') {
                $hasClient = !empty($approval->client_id);
                $isAdminOrSuperAdmin = $actor && in_array($actor->role ?? '', ['super-admin', 'admin']);

                // If admin/super-admin approves, they can bypass client review and go directly to production
                if ($isAdminOrSuperAdmin) {
                    // Admin/Super-admin approval → skip client review, go straight to ready-for-production
                    DB::table('approvals')->where('id', $id)->update([
                        'approval_stage' => 'completed',
                        'status' => 'approved',
                        'updated_at' => now(),
                    ]);
                    $workflow = $this->findLinkedWorkflow($approval);
                    if ($workflow) {
                        DB::table('workflows')->where('id', $workflow->id)->update([
                            'stage' => 'ready-for-production',
                            'updated_at' => now(),
                        ]);
                    }
                    if (! $suppressSideEffects) {
                        app(NotificationService::class)->notifyAdminApprovedFinal($approval->title, $approval->client_id);
                    }
                } elseif ($hasClient) {
                    // Non-admin with client → move to client-pending for client review
                    DB::table('approvals')->where('id', $id)->update([
                        'approval_stage' => 'client-pending',
                        'status' => 'pending',
                        'updated_at' => now(),
                    ]);
                    if (! $suppressSideEffects) {
                        app(NotificationService::class)->notifyAdminApprovedFinal($approval->title, $approval->client_id);
                    }
                } else {
                    // Internal / no client → skip client review, go straight to ready-for-production
                    DB::table('approvals')->where('id', $id)->update([
                        'approval_stage' => 'completed',
                        'status' => 'approved',
                        'updated_at' => now(),
                    ]);
                    $workflow = $this->findLinkedWorkflow($approval);
                    if ($workflow) {
                        DB::table('workflows')->where('id', $workflow->id)->update([
                            'stage' => 'ready-for-production',
                            'updated_at' => now(),
                        ]);
                    }
                    if (! $suppressSideEffects) {
                        app(NotificationService::class)->notifyAdminApprovedFinal($approval->title, $approval->client_id);
                    }
                }
            } else {
                // Rejected/revision → workflow = revision
                $workflow = $this->findLinkedWorkflow($approval);
                if ($workflow) {
                    DB::table('workflows')->where('id', $workflow->id)->update([
                        'stage' => 'revision',
                        'revision_notes' => $reason,
                        'updated_at' => now(),
                    ]);
                }
                DB::table('contents')->where('id', $approval->content_id)->update([
                    'status' => 'revision',
                    'updated_at' => now(),
                ]);
                if (! $suppressSideEffects) {
                    app(NotificationService::class)->notifyAdminRejectedFinal($approval->title, $reason, $approval->client_id);
                }
            }
        } elseif ($approval->approval_stage === 'client-pending') {
            // CLIENT PENDING: Client approves — moves to ready-for-production
            if ($status === 'approved') {
                DB::table('approvals')->where('id', $id)->update([
                    'approval_stage' => 'completed',
                    'status' => 'approved',
                    'updated_at' => now(),
                ]);
                $workflow = $this->findLinkedWorkflow($approval);
                if ($workflow) {
                    DB::table('workflows')->where('id', $workflow->id)->update([
                        'stage' => 'ready-for-production',
                        'updated_at' => now(),
                    ]);
                } else {
                    Log::warning("Approval #{$id} client-pending approved but no linked workflow found", [
                        'content_id' => $approval->content_id,
                        'title' => $approval->title,
                    ]);
                }
                if (! $suppressSideEffects) {
                    app(NotificationService::class)->notifyClientApprovedFinal($approval->title);
                }
            } else {
                // Rejected/revision → workflow = revision
                $workflow = $this->findLinkedWorkflow($approval);
                if ($workflow) {
                    DB::table('workflows')->where('id', $workflow->id)->update([
                        'stage' => 'revision',
                        'revision_notes' => $reason,
                        'updated_at' => now(),
                    ]);
                }
                DB::table('contents')->where('id', $approval->content_id)->update([
                    'status' => 'revision',
                    'updated_at' => now(),
                ]);
                if (! $suppressSideEffects) {
                    app(NotificationService::class)->notifyClientRejectedFinal($approval->title, $reason);
                }
            }
        }

        if (! $suppressSideEffects) {
            $isStaff = ! $isClient;
            app(NotificationService::class)->sendNotification(
                text: "Approval #{$id} {$status}",
                type: $status === 'approved' ? 'success' : ($status === 'rejected' ? 'error' : 'info'),
                link: route('approvals', absolute: false),
                forRole: $isStaff ? 'client' : 'manager',
                clientId: $isStaff ? $approval->client_id : null,
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
        if (empty($this->selectedItems)) {
            return;
        }
        $actor = Auth::user() ?? Auth::guard('client')->user();
        if ($action === 'rejected' && $actor && ($actor->role ?? 'client') === 'client') {
            $this->dispatch('toast', message: 'Clients cannot reject approvals', type: 'error');

            return;
        }
        $pending = DB::table('approvals')->where('status', 'pending')->whereNull('deleted_at')->pluck('id')->toArray();
        $targets = array_values(array_intersect($this->selectedItems, $pending));
        $count = 0;
        foreach ($targets as $id) {
            if ($this->applyStatus($id, $action, $reason, suppressSideEffects: true)) {
                $count++;
            }
        }
        if ($count > 0) {
            $isStaff = $actor && ($actor->role ?? 'client') !== 'client';
            app(NotificationService::class)->sendNotification(
                text: "{$count} approval(s) {$action} by {$actor?->name}",
                type: $action === 'approved' ? 'success' : ($action === 'rejected' ? 'error' : 'info'),
                link: route('approvals', absolute: false),
                forRole: 'manager',
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
        $pending = DB::table('approvals')->where('status', 'pending')->whereNull('deleted_at')->pluck('id')->toArray();
        $this->selectedItems = $pending;
    }

    public function deselectAll(): void
    {
        $this->selectedItems = [];
    }

    public function deleteApproval(int $id): void
    {
        if (! $this->isManager) {
            $this->dispatch('toast', message: 'Unauthorized action', type: 'error');

            return;
        }
        $approval = Approval::findOrFail($id);
        if ($approval->status === 'pending') {
            $this->dispatch('toast', message: 'Cannot delete a pending approval', type: 'error');

            return;
        }
        $approval->delete();
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
        if (! $this->isManager) {
            return;
        }
        $row = DB::table('approvals')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) {
            return;
        }
        $this->editingId = (int) $row->id;
        $this->formTitle = $row->title ?? '';
        $this->formNotes = $row->notes ?? '';
        $this->formClientId = (int) ($row->client_id ?? 0);
        $this->formContentId = $row->content_id ?? null;
        $this->formType = $row->type ?? 'post';
        $this->formReferenceFile = $row->reference_file ?? '';
        $this->formAttachments = is_array($row->attachments ?? null) ? ($row->attachments ?? []) : json_decode($row->attachments ?? '[]', true) ?? [];
        $this->formAttachmentsJson = json_encode($this->formAttachments);
        $this->showDetail = false;
        $this->showForm = true;
    }

    public function create(): void
    {
        if (! $this->isManager) {
            return;
        }
        $this->editingId = 0;
        $this->formTitle = '';
        $this->formNotes = '';
        $this->formClientId = 0;
        $this->formContentId = null;
        $this->formType = 'post';
        $this->formReferenceFile = '';
        $this->formAttachments = [];
        $this->formAttachmentsJson = '[]';
        $this->showForm = true;
    }

    public function createOrUpdate(): void
    {
        if (! $this->isManager) {
            return;
        }
        $actor = Auth::user();
        $data = [
            'title' => $this->formTitle,
            'type' => $this->formType,
            'client_id' => $this->formClientId ?: null,
            'content_id' => $this->formContentId ?: null,
            'notes' => $this->formNotes,
            'reference_file' => $this->formReferenceFile ?: null,
            'attachments' => $this->formAttachments ? array_values($this->formAttachments) : null,
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
        $this->formAttachments = [];
        $this->formAttachmentsJson = '[]';
        $this->resetPage();
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->formAttachments = [];
        $this->formAttachmentsJson = '[]';
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
        $pending = DB::table('approvals')->where('status', 'pending')->whereNull('deleted_at')->pluck('id')->toArray();
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
        if (! trim($this->reasonText)) {
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
            if ($this->applyStatus($id, $this->reasonAction, trim($this->reasonText), suppressSideEffects: true)) {
                $count++;
            }
        }
        if ($count > 0) {
            $isStaff = $actor && ($actor->role ?? 'client') !== 'client';
            app(NotificationService::class)->sendNotification(
                text: "{$count} approval(s) {$this->reasonAction} by {$actor?->name}",
                type: $this->reasonAction === 'approved' ? 'success' : ($this->reasonAction === 'rejected' ? 'error' : 'info'),
                link: route('approvals', absolute: false),
                forRole: 'manager',
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
            ->whereNull('approvals.deleted_at')
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
        if (! $this->commentText || ! $this->detailId) {
            return;
        }
        $actor = Auth::user() ?? Auth::guard('client')->user();
        $commentUserId = $actor instanceof User ? $actor?->id : null;
        DB::table('approval_comments')->insert([
            'approval_id' => $this->detailId,
            'user_id' => $commentUserId,
            'user_name' => $actor?->name ?? 'Unknown',
            'text' => $this->commentText,
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(ActivityLogger::class)->record($actor, "Commented on approval #{$this->detailId}");
        $this->commentText = '';
        $this->dispatch('toast', message: 'Comment added', type: 'success');
    }

    public function getDiscussionComments()
    {
        if (! $this->detailId) {
            return collect();
        }

        return Comment::with('user')
            ->where('commentable_type', Approval::class)
            ->where('commentable_id', $this->detailId)
            ->latest()
            ->get();
    }

    public function getDetailAttachments(): array
    {
        if (!$this->detailId) return [];
        $atts = [];

        $approval = DB::table('approvals')->where('id', $this->detailId)->whereNull('deleted_at')->first();
        if (!$approval) return [];

        if (!empty($approval->attachments)) {
            $raw = $approval->attachments;
            if (is_string($raw)) $raw = json_decode($raw, true);
            if (is_array($raw)) $atts = array_values(array_filter($raw, fn($a) => is_array($a)));
        }

        if (!empty($approval->content_id)) {
            $contentAtts = DB::table('contents')->where('id', $approval->content_id)->value('attachments');
            if (!empty($contentAtts)) {
                if (is_string($contentAtts)) $contentAtts = json_decode($contentAtts, true);
                if (is_array($contentAtts)) {
                    $validContentAtts = array_values(array_filter($contentAtts, fn($a) => is_array($a)));
                    $atts = array_merge($atts, $validContentAtts);
                }
            }
        }

        $atts = array_map(function ($a) {
            if (empty($a['url']) && !empty($a['id'])) {
                $file = DB::table('files')->where('id', $a['id'])->first();
                if ($file && $file->path) {
                    $a['url'] = Storage::url($file->path);
                }
            }
            return $a;
        }, $atts);

        return array_values(array_filter($atts, fn($a) => !empty($a['url'])));
    }

    public function getLinkedContent()
    {
        if (!$this->detailId) return null;

        $appr = DB::table('approvals')->where('id', $this->detailId)->whereNull('deleted_at')->first();
        if (!$appr || empty($appr->content_id)) return null;

        return DB::table('contents')
            ->leftJoin('clients', 'contents.client_id', '=', 'clients.id')
            ->select('contents.*', 'clients.name as client_name')
            ->where('contents.id', $appr->content_id)
            ->first();
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

        $actor = Auth::user() ?? Auth::guard('client')->user();

        if ($hasFiles && !$hasText) {
            $existingRaw = DB::table('approvals')->where('id', $this->detailId)->whereNull('deleted_at')->value('attachments');
            $existing = $existingRaw ? (json_decode($existingRaw, true) ?: []) : [];
            $merged = array_values(array_merge($existing, $attachments));

            DB::table('approvals')->where('id', $this->detailId)->update([
                'attachments' => json_encode($merged),
                'updated_at' => now(),
            ]);

            $this->commentAttachments = '[]';
            $this->resetPage();
            $this->dispatch('toast', message: 'Files attached', type: 'success');
            return;
        }

        Comment::create([
            'commentable_type' => Approval::class,
            'commentable_id' => $this->detailId,
            'user_id' => $actor?->id,
            'body' => $this->commentText,
            'attachments' => $attachments ?: null,
        ]);

        app(ActivityLogger::class)->record($actor, "Commented on approval #{$this->detailId}");

        $this->commentText = '';
        $this->commentAttachments = '[]';
        $this->dispatch('tiptap-set-content', name: 'apprComment', html: '');
        $this->dispatch('toast', message: 'Comment added', type: 'success');
    }

    public function getPickableFiles(?string $search = null, ?int $clientId = null, ?int $folderId = null): array
    {
        $q = File::select('id', 'name', 'type', 'size')
            ->orderBy('name');

        if ($clientId) {
            $q->where('client_id', $clientId);
        }
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
        $q = \App\Models\Folder::select('id', 'name')
            ->orderBy('name');

        if ($clientId) {
            $q->where('client_id', $clientId);
        }
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
            $folder = \App\Models\Folder::select('id', 'name', 'parent_id')->find($current);
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

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6" x-data>
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
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3" aria-live="polite">
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
                    <x-search-input wire="search" placeholder="Search approvals..." class="w-full sm:w-56" />
                </div>
                <div><label class="form-label">Type</label><select wire:model.live="typeFilter" class="form-select w-auto"><option value="">All Types</option><option value="post">Post</option><option value="reel">Reel</option><option value="story">Story</option><option value="video">Video</option><option value="carousel">Carousel</option><option value="blog">Blog</option></select></div>
                <div><label class="form-label">Status</label><select wire:model.live="statusFilter" class="form-select w-auto"><option value="">All Status</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="revision">Revision</option><option value="rejected">Rejected</option></select></div>
                <div><label class="form-label">Stage</label><select wire:model.live="stageFilter" class="form-select w-auto"><option value="">All Stages</option><option value="first">First Approval</option><option value="admin-pending">Admin Review</option><option value="client-pending">Client Review</option><option value="completed">Completed (Internal)</option></select></div>
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
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @forelse($this->approvals as $a)
                <div wire:key="appr-card-{{ $a->id }}" wire:loading.class="opacity-60 pointer-events-none" wire:target="openDetail({{ $a->id }})" class="bg-white rounded-2xl border border-gray-100 p-3 sm:p-4 hover:shadow-md transition-all cursor-pointer active:scale-[0.99] flex flex-col" role="button" tabindex="0" wire:click="openDetail({{ $a->id }})" x-on:keydown.enter="$wire.openDetail({{ $a->id }})">
                    @if($this->isManager && $a->status === 'pending')
                    <div class="mb-2">
                        <input type="checkbox" wire:change.stop="toggleSelect({{ $a->id }})" {{ in_array($a->id, $selectedItems) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-[var(--brand)]">
                    </div>
                    @endif
                    <div class="flex items-center gap-2.5 mb-2">
                        <div class="hidden sm:flex h-9 w-9 items-center justify-center rounded-xl bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)] shrink-0">
                            <i class="fas fa-check-double text-sm"></i>
                        </div>
                        <h4 class="text-sm font-bold text-gray-900 break-words line-clamp-2">{{ $a->title ?? 'Untitled' }}</h4>
                    </div>

                    <div class="flex items-center gap-1.5 flex-wrap mb-2">
                        <span class="badge badge-{{ $a->status }}">{{ ucfirst($a->status) }}</span>
                        @if($a->approval_stage === 'first')
                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 font-medium whitespace-nowrap">First Approval</span>
                        @elseif($a->approval_stage === 'admin-pending')
                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-600 font-medium whitespace-nowrap">Admin Review</span>
                        @elseif($a->approval_stage === 'client-pending')
                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-purple-50 text-purple-600 font-medium whitespace-nowrap">Client Review</span>
                        @elseif($a->approval_stage === 'completed')
                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-green-50 text-green-600 font-medium whitespace-nowrap">Completed</span>
                        @endif
                        @if($a->type)<span class="badge badge-{{ $a->type }}">{{ ucfirst($a->type) }}</span>@endif
                    </div>

                    <div class="text-xs text-gray-500 mb-2 space-y-0.5">
                        <p class="truncate">Client: {{ $a->client_name ?? '—' }}</p>
                        <p class="truncate">By: {{ $a->submitter_name ?? '—' }}</p>
                        <p>{{ $a->created_at ? \Carbon\Carbon::parse($a->created_at)->diffForHumans() : '' }}</p>
                    </div>

                    @if($a->notes)<p class="text-xs text-gray-600 line-clamp-2 mb-2">{{ Str::limit($a->notes, 80) }}</p>@endif

                    @if($a->reference_file)
                    <div class="mb-2">
                        @if($this->isImage($a->reference_file))
                        <img src="{{ $this->fileUrl($a->reference_file) }}" alt="Reference file" class="h-16 w-full rounded-lg object-cover border border-gray-200" loading="lazy" />
                        @else
                        <span class="inline-flex items-center gap-1 text-xs text-[var(--brand)]"><i class="fas fa-paperclip"></i> Attachment</span>
                        @endif
                    </div>
                    @endif

                    @php
                        $cc = $this->commentCounts[$a->id] ?? 0;
                        $canActPending = $a->status === 'pending' && (
                            ($a->approval_stage === 'first' && $this->isManager)
                            || ($a->approval_stage === 'admin-pending' && $this->isManager)
                            || ($a->approval_stage === 'client-pending' && !$this->isManager)
                        );
                        $canActRevision = $a->status === 'revision' && $a->approval_stage === 'first' && $this->isManager;
                        $canAct = $canActPending || $canActRevision;
                        $canRevision = $a->status === 'pending' && (
                            ($a->approval_stage === 'first' && $this->isManager)
                            || ($a->approval_stage === 'admin-pending' && $this->isManager)
                            || ($a->approval_stage === 'client-pending' && !$this->isManager)
                        );
                        $canReject = ($a->status === 'pending' && $a->approval_stage !== 'client-pending' && $this->isManager)
                            || ($canActRevision);
                        $isInternal = empty($a->client_id);
                        $approveMsg = match(true) {
                            $a->approval_stage === 'first' => 'Content will be approved and workflow started.',
                            $a->approval_stage === 'admin-pending' && $isInternal => 'This is internal — approval will complete directly.',
                            $a->approval_stage === 'admin-pending' && $this->isManager => 'As admin/super-admin, this will move directly to Ready for Production.',
                            $a->approval_stage === 'admin-pending' => 'This will send it to client for final approval.',
                            $a->approval_stage === 'client-pending' => 'This will move the content to Ready for Production for final publishing.',
                            default => 'This will mark the item as approved.',
                        };
                    @endphp
                    <div class="flex flex-wrap items-center gap-2 mt-auto pt-2 border-t border-gray-50" x-data="{ open: false }" x-on:click.stop>
                        @if($canAct)
                            <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve this item?', message: '{{ $approveMsg }}', type: 'info', action: 'updateStatus', params: [{{ $a->id }}, 'approved'] })" class="btn btn-success btn-sm flex-shrink-0"><i class="fas fa-check text-xs"></i> Approve</button>
                        @endif

                        <button type="button" wire:click="openDetail({{ $a->id }})" class="btn btn-ghost btn-sm relative flex-shrink-0" title="View comments">
                            <i class="fas fa-comments text-xs"></i>
                            @if($cc > 0)<span class="badge badge-pending ml-1">{{ $cc }}</span>@endif
                        </button>

                        @if($canRevision || $canReject)
                        <div class="relative">
                            <button type="button" x-on:click="open = !open" class="btn btn-ghost btn-icon btn-sm" aria-label="More actions" title="More">
                                <i class="fas fa-ellipsis-h text-xs text-gray-500"></i>
                            </button>
                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition class="absolute right-0 mt-1 w-44 bg-white border border-gray-100 rounded-lg shadow-lg z-20 py-1">
                                @if($canRevision)
                                    <button type="button" wire:click="openReasonModal({{ $a->id }}, 'revision')" x-on:click="open = false" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                        <i class="fas fa-pen text-xs text-gray-400"></i> Request Revision
                                    </button>
                                @endif
                                @if($canReject)
                                    <button type="button" wire:click="openReasonModal({{ $a->id }}, 'rejected')" x-on:click="open = false" class="w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50 flex items-center gap-2">
                                        <i class="fas fa-times text-xs"></i> Reject
                                    </button>
                                @endif
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
                @empty
                <div class="col-span-full bg-white rounded-2xl border border-gray-100 p-12 text-center">
                    <i class="fas fa-check-double text-4xl text-gray-200 mb-3"></i>
                    <p class="text-sm text-gray-400">No approvals found</p>
                </div>
                @endforelse
            </div>

            <div>{{ $this->approvals->links() }}</div>

            {{-- ========== APPROVAL DETAIL MODAL ========== --}}
            @if($showDetail)
            @php $appr = $this->getDetailApproval(); $comments = $this->getDetailComments(); @endphp
            @if($appr)
            @php
                $apprStatusBadge = match($appr->status) {
                    'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'rejected' => 'bg-red-50 text-red-700 border-red-200',
                    'revision' => 'bg-amber-50 text-amber-700 border-amber-200',
                    default    => 'bg-blue-50 text-blue-700 border-blue-200',
                };
                $apprTypeBadge = match($appr->type) {
                    'reel'     => 'bg-pink-50 text-pink-700 border-pink-200',
                    'post'     => 'bg-blue-50 text-blue-700 border-blue-200',
                    'story'    => 'bg-purple-50 text-purple-700 border-purple-200',
                    'video'    => 'bg-red-50 text-red-700 border-red-200',
                    'carousel' => 'bg-amber-50 text-amber-700 border-amber-200',
                    'blog'     => 'bg-green-50 text-green-700 border-green-200',
                    default    => 'bg-gray-50 text-gray-600 border-gray-200',
                };
                $linkedContent = $this->getLinkedContent();
                $contentAttachments = [];
                if ($linkedContent) {
                    $raw = $linkedContent->attachments;
                    if (!is_null($raw)) {
                        if (is_string($raw)) $raw = json_decode($raw, true);
                        if (is_array($raw)) $contentAttachments = array_values(array_filter($raw, fn($a) => is_array($a)));
                    }
                    $contentAttachments = array_map(function ($a) {
                        if (empty($a['url']) && !empty($a['id'])) {
                            $file = DB::table('files')->where('id', $a['id'])->first();
                            if ($file && $file->path) {
                                $a['url'] = Storage::url($file->path);
                            }
                        }
                        return $a;
                    }, $contentAttachments);
                    $contentAttachments = array_values(array_filter($contentAttachments, fn($a) => !empty($a['url'])));
                }
            @endphp
            <div wire:transition.opacity.duration.150ms class="modal-overlay" x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">
                <div class="modal-box max-w-3xl" x-on:click.stop>

                    {{-- Header --}}
                    <div class="modal-header border-b border-gray-100 pb-3">
                        <div class="flex-1 min-w-0 pr-2">
                            <div class="flex flex-wrap items-center gap-1.5 mb-1.5">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $apprStatusBadge }} whitespace-nowrap">{{ ucfirst($appr->status) }}</span>
                                @if ($appr->type)
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $apprTypeBadge }} whitespace-nowrap">{{ ucfirst($appr->type) }}</span>
                                @endif
                            </div>
                            <h3 class="text-base sm:text-lg font-bold text-gray-900 leading-snug break-words">{{ $appr->title ?? 'Approval Details' }}</h3>
                        </div>
                        <button wire:click="$set('showDetail', false)" class="btn btn-ghost btn-icon btn-sm shrink-0" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>

                    <div class="modal-body space-y-5 max-h-[70vh] overflow-y-auto">

                        {{-- Approval Attachments --}}
                        @php $aAtts = $this->getDetailAttachments(); @endphp
                        @if (count($aAtts) > 0)
                            <div>
                                @include('livewire.partials.attachment-display', ['attachments' => $aAtts, 'label' => 'Approval Attachments'])
                            </div>
                        @endif

                        {{-- Notes --}}
                        @if($appr->notes)
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Notes</p>
                                <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $appr->notes }}</p>
                            </div>
                        @endif

                        {{-- Metadata --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4 text-sm">
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Client</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-building text-gray-400"></i> {{ $appr->client_name ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Submitted By</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-user text-gray-400"></i> {{ $appr->submitter_name ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Date</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5"><i class="fas fa-calendar-alt text-gray-400"></i> {{ $appr->created_at ? \App\Support\NepaliDate::display($appr->created_at) : '—' }}</p>
                            </div>
                        </div>

                        {{-- Linked Content --}}
                        @if($linkedContent)
                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 sm:p-4">
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-3 flex items-center gap-1.5">
                                    <i class="fas fa-link text-gray-400"></i> Linked Content
                                </p>
                                <div class="space-y-3">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900 break-words">{{ $linkedContent->title }}</p>
                                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                                            @if($linkedContent->platform)
                                                <span class="inline-flex items-center rounded-md bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap">{{ ucfirst($linkedContent->platform) }}</span>
                                            @endif
                                            @if($linkedContent->type)
                                                <span class="inline-flex items-center rounded-md bg-purple-50 text-purple-700 border border-purple-200 px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap">{{ ucfirst($linkedContent->type) }}</span>
                                            @endif
                                            @if($linkedContent->status)
                                                <span class="inline-flex items-center rounded-md bg-gray-100 text-gray-600 border border-gray-200 px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap">{{ ucwords(str_replace('-', ' ', $linkedContent->status)) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @if($linkedContent->caption)
                                        <div>
                                            <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-0.5">Caption</p>
                                            <p class="text-sm text-gray-600 whitespace-pre-line break-words">{{ Str::limit($linkedContent->caption, 200) }}</p>
                                        </div>
                                    @endif
                                    @if($linkedContent->date)
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 text-xs text-gray-500">
                                            <span><i class="fas fa-calendar-alt mr-1"></i>{{ \App\Support\NepaliDate::display($linkedContent->date) }}</span>
                                            @if($linkedContent->due_date)
                                                <span><i class="fas fa-clock mr-1"></i>Due {{ \App\Support\NepaliDate::display($linkedContent->due_date) }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    @if($linkedContent->hashtags && trim($linkedContent->hashtags) !== '')
                                        <div class="flex flex-wrap gap-1">
                                            @foreach(explode(',', $linkedContent->hashtags) as $tag)
                                                @php $tag = trim($tag); @endphp
                                                @if($tag !== '')
                                                    <span class="inline-flex items-center rounded-md bg-gray-100 text-gray-600 px-2 py-0.5 text-[11px] break-all">{{ $tag }}</span>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                    @if(count($contentAttachments) > 0)
                                        <div class="border-t border-gray-200 pt-3">
                                            @include('livewire.partials.attachment-display', ['attachments' => $contentAttachments, 'label' => 'Content Attachments'])
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- Action Buttons --}}
                        @if(in_array($appr->status, ['approved','rejected']))
                            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-2.5 text-sm text-amber-700 flex items-center gap-2">
                                <i class="fas fa-lock"></i> Decision finalized — no further actions can be taken.
                            </div>
                        @endif

                        @if($appr->status === 'pending')
                            @php
                                $isInternalDetail = empty($appr->client_id);
                                $detailApproveMsg = match($appr->approval_stage) {
                                    'first' => 'Content will be approved and workflow started.',
                                    'admin-pending' => $isInternalDetail ? 'This is internal — approval will complete directly.' : ($this->isManager ? 'As admin/super-admin, this will move directly to Ready for Production.' : 'This will send it to client for final approval.'),
                                    'client-pending' => 'This will move the content to Ready for Production.',
                                    default => 'This will mark the item as approved.',
                                };
                            @endphp
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve this item?', message: '{{ addslashes($detailApproveMsg) }}', type: 'info', action: 'updateStatus', params: [{{ $appr->id }}, 'approved'] })" class="btn btn-success btn-sm flex-1 sm:flex-initial"><i class="fas fa-check text-xs"></i> Approve</button>
                                <button type="button" wire:click="openReasonModal({{ $appr->id }}, 'revision')" class="btn btn-secondary btn-sm flex-1 sm:flex-initial"><i class="fas fa-pen text-xs"></i> Request Revision</button>
                                @if($this->isManager)
                                    <button type="button" wire:click="openReasonModal({{ $appr->id }}, 'rejected')" class="btn btn-danger btn-sm flex-1 sm:flex-initial"><i class="fas fa-times text-xs"></i> Reject</button>
                                @endif
                            </div>
                        @elseif($appr->status === 'revision')
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Approve this item?', message: 'This will mark the item as approved.', type: 'info', action: 'updateStatus', params: [{{ $appr->id }}, 'approved'] })" class="btn btn-success btn-sm flex-1 sm:flex-initial"><i class="fas fa-check text-xs"></i> Approve</button>
                                @if($this->isManager)
                                    <button type="button" wire:click="openReasonModal({{ $appr->id }}, 'rejected')" class="btn btn-danger btn-sm flex-1 sm:flex-initial"><i class="fas fa-times text-xs"></i> Reject</button>
                                @endif
                            </div>
                        @endif

                        {{-- Discussion --}}
                        <div class="border-t border-gray-100 pt-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fas fa-comments text-gray-400"></i> Discussion
                            </h4>

                            {{-- Legacy comments --}}
                            @if($comments->count())
                                <div class="space-y-2 max-h-40 overflow-y-auto mb-3">
                                    @foreach($comments as $c)
                                        <div class="{{ $c->is_system ? 'bg-blue-50 border border-blue-100' : 'bg-gray-50 border border-gray-100' }} rounded-lg px-3 py-2">
                                            <p class="text-sm font-medium {{ $c->is_system ? 'text-blue-700' : 'text-gray-900' }}">{{ $c->user_name }} <span class="text-[10px] text-gray-400 font-normal">{{ \Carbon\Carbon::parse($c->created_at)->diffForHumans() }}</span></p>
                                            <p class="text-sm {{ $c->is_system ? 'text-blue-600 italic' : 'text-gray-600' }}">{{ $c->text }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- New discussion --}}
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
                                    @if($comments->count() === 0)
                                        <p class="text-xs text-gray-400 text-center py-2">No comments yet. Start the discussion.</p>
                                    @endif
                                @endforelse
                            </div>

                            {{-- Comment form --}}
                            <div class="border-t border-gray-100 pt-3">
                                <x-tiptap-editor wire="commentText" name="apprComment" placeholder="Add a comment..." />
                                <div class="flex items-center justify-between mt-2">
                                    <x-file-picker :clientId="$appr->client_id ?? null" wire="commentAttachments" :initial="$commentAttachments" />
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

                        {{-- Delete (manager only, non-pending) --}}
                        @if($this->isManager && $appr->status !== 'pending')
                            <div class="pt-2 border-t border-gray-100">
                                <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete Approval?', message: 'This approval and its comments will be removed.', type: 'danger', action: 'deleteApproval', params: [{{ $appr->id }}] })" class="btn btn-ghost btn-sm text-red-500" aria-label="Delete approval"><i class="fas fa-trash text-xs"></i> Delete</button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif
            @endif

            {{-- Create / Edit Form Modal --}}
            @if($showForm && $this->isManager)
            <div class="modal-overlay" wire:click.self="$set('showForm',false)" x-on:keydown.escape.window="$wire.set('showForm',false)">
                <div class="modal-box max-w-lg">
                    <div class="modal-header"><h3 class="text-base font-bold text-gray-900">{{ $editingId > 0 ? 'Edit Approval' : 'New Approval' }}</h3><button wire:click="$set('showForm',false)" class="btn btn-ghost btn-icon btn-sm" aria-label="Close"><i class="fas fa-times"></i></button></div>
                    <div class="modal-body space-y-4">
                        <div><label class="form-label">Title</label><input type="text" wire:model="formTitle" class="form-input" placeholder="Approval title" /></div>
                        {{-- Attachments — prominent, top of form --}}
                        <div class="border border-dashed border-gray-200 rounded-xl bg-gray-50/50 p-4">
                            <label class="form-label mb-2"><i class="fas fa-paperclip text-gray-400 mr-1"></i> Attachments</label>
                            <x-file-picker :clientId="$formClientId ?: null" wire="formAttachmentsJson" :initial="$formAttachments" wireClientId="formClientId" />
                            @if ($editingId > 0 && count($formAttachments) > 0)
                                <div class="mt-3">
                                    @include('livewire.partials.attachment-display', ['attachments' => $formAttachments, 'label' => ''])
                                </div>
                            @endif
                        </div>
                        <div><label class="form-label">Type</label><select wire:model="formType" class="form-select"><option value="post">Post</option><option value="reel">Reel</option><option value="story">Story</option><option value="video">Video</option><option value="carousel">Carousel</option><option value="blog">Blog</option></select></div>
                        <div><label class="form-label">Client</label><select wire:model="formClientId" class="form-select"><option value="0">Internal / Own Company</option>@foreach($this->clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                        @if ($formClientId)
                            <div><label class="form-label">Link to Content <span class="text-gray-400 text-xs">(optional)</span></label><select wire:model="formContentId" class="form-select"><option value="">No linked content</option>@foreach($this->getAvailableContent() as $content)<option value="{{ $content->id }}">{{ $content->title }} — {{ \Carbon\Carbon::parse($content->date)->format('M j') }} ({{ ucfirst($content->platform) }})</option>@endforeach</select></div>
                        @endif
                        <div><label class="form-label">Notes</label><textarea wire:model="formNotes" class="form-textarea" rows="3" placeholder="Notes..."></textarea></div>
                        <div><label class="form-label">Reference File</label><input type="text" wire:model="formReferenceFile" class="form-input" placeholder="Path or URL" /></div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button wire:click="cancelForm" class="btn btn-secondary btn-sm">Cancel</button>
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
