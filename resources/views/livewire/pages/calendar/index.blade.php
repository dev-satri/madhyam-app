<?php

use App\Models\Comment;
use App\Models\Content;
use App\Models\File;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\PackageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
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

    public string $caption = '';

    public string $hashtags = '';

    public string $referenceFile = '';

    public array $formAttachments = [];

    public string $commentText = '';

    public array $commentAttachments = [];

    public string $commentAttachmentsJson = '[]';

    public ?int $selectedContentId = null;

    public bool $showContentDiscussion = false;

    public array $clients = [];

    public const PLATFORMS = ['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'linkedin'];

    public const TYPES = ['reel', 'post', 'story', 'video', 'carousel', 'blog'];

    public const STATUSES = ['draft', 'scripting', 'in-review', 'revision', 'published'];

    public array $contentByDate = [];

    public function mount(): void
    {
        $this->currentMonth = (int) now()->month;
        $this->currentYear = (int) now()->year;
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
        $this->currentMonth = (int) now()->month;
        $this->currentYear = (int) now()->year;
        $this->loadMonthContent();
    }

    public function loadMonthContent(): void
    {
        $start = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->startOfWeek(Carbon::SUNDAY);
        $end = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $query = DB::table('contents')
            ->leftJoin('clients', 'contents.client_id', '=', 'clients.id')
            ->whereBetween('contents.date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->select('contents.*', 'clients.name as client_name');

        // Tenant isolation for client portal — same view serves /client/content-planner.
        // Raw DB::table bypasses Content::ScopesToClientAccount, so scope manually.
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
            $query->where('contents.platform', $this->platformFilter);
        }
        if ($this->statusFilter) {
            $query->where('contents.status', $this->statusFilter);
        }
        if ($this->clientFilter) {
            $query->where('contents.client_id', $this->clientFilter);
        }

        $allContent = $query->orderBy('contents.date')->get();

        $this->contentByDate = [];
        foreach ($allContent as $item) {
            $this->contentByDate[$item->date][] = $item;
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
            $platform = $item->platform ?? 'unknown';
            $summary[$platform] = ($summary[$platform] ?? 0) + 1;
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

        if ($content->status === 'published') {
            $this->dispatch('toast', message: 'Published content cannot be edited', type: 'error');

            return;
        }

        $this->editingId = $id;
        $this->title = $content->title;
        $this->formClientId = $content->client_id ? (int) $content->client_id : null;
        $this->formDate = $content->date instanceof Carbon ? $content->date->format('Y-m-d') : $content->date;
        $this->formDueDate = $content->due_date instanceof Carbon ? $content->due_date->format('Y-m-d') : ($content->due_date ?? '');
        $this->formPlatforms = $content->platform ? [$content->platform] : [];
        $this->formTypes = $content->type ? [$content->type] : [];
        $this->formStatus = $content->status;
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
        $this->showForm = true;
        $this->showDayDetail = false;
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'formClientId' => 'nullable|integer',
            'formDate' => 'required|date',
            'formDueDate' => 'nullable|date',
            'formPlatforms' => 'required|array|min:1',
            'formTypes' => 'required|array|min:1',
            'formStatus' => 'required|string|in:draft,scripting,in-review,revision,published',
        ]);

        try {
            if ($this->editingId) {
                DB::table('contents')->where('id', $this->editingId)->update([
                    'title' => $this->title,
                    'client_id' => $this->formClientId,
                    'date' => $this->formDate,
                    'due_date' => $this->formDueDate ?: null,
                    'platform' => $this->formPlatforms[0] ?? 'instagram',
                    'type' => $this->formTypes[0] ?? 'post',
                    'status' => $this->formStatus,
                    'caption' => $this->caption,
                    'hashtags' => $this->hashtags,
                    'reference_file' => $this->referenceFile,
                    'attachments' => $this->formAttachments ? json_encode(array_values($this->formAttachments)) : null,
                    'updated_at' => now(),
                ]);
                $this->dispatch('toast', message: 'Content updated successfully', type: 'success');
            } else {
                $count = 0;
                foreach ($this->formPlatforms as $platform) {
                    foreach ($this->formTypes as $type) {
                        DB::table('contents')->insert([
                            'title' => $this->title,
                            'client_id' => $this->formClientId ?: null,
                            'date' => $this->formDate,
                            'due_date' => $this->formDueDate ?: null,
                            'platform' => $platform,
                            'type' => $type,
                            'status' => $this->formStatus,
                            'caption' => $this->caption,
                            'hashtags' => $this->hashtags,
                            'reference_file' => $this->referenceFile,
                            'attachments' => $this->formAttachments ? json_encode(array_values($this->formAttachments)) : null,
                            'created_by' => auth()->id(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $count++;
                        if ($this->formClientId) {
                            PackageService::recordContent(
                                $this->formClientId,
                                $this->formStatus === 'published' ? 'published' : 'created',
                                $type,
                            );
                        }
                    }
                }
                $this->dispatch('toast', message: "Created {$count} content item(s)", type: 'success');
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
        $content = Content::findOrFail($id);
        if (in_array($content->status, ['published', 'in-review', 'scheduled'])) {
            $this->dispatch('toast', message: 'Cannot delete content with status: ' . $content->status, type: 'error');

            return;
        }
        $content->delete();
        $this->loadMonthContent();
        $this->dispatch('contentUpdated');
        $this->dispatch('toast', message: 'Content deleted successfully', type: 'success');
    }

    public function getStats(): array
    {
        $query = DB::table('contents');

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
            $query->where('platform', $this->platformFilter);
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
            ->select('contents.*', 'clients.name as client_name')
            ->orderBy('contents.date', 'desc');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('contents.title', 'like', "%{$this->search}%")
                    ->orWhere('clients.name', 'like', "%{$this->search}%");
            });
        }
        if ($this->platformFilter) {
            $query->where('contents.platform', $this->platformFilter);
        }
        if ($this->statusFilter) {
            $query->where('contents.status', $this->statusFilter);
        }
        if ($this->clientFilter) {
            $query->where('contents.client_id', $this->clientFilter);
        }

        return $query->get()->toArray();
    }

    public function getPlatformData(): array
    {
        return DB::table('contents')
            ->select('platform', DB::raw('COUNT(*) as count'))
            ->groupBy('platform')
            ->pluck('count', 'platform')
            ->toArray();
    }

    public function getTypeData(): array
    {
        return DB::table('contents')
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();
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

            $platform = ucfirst($content->platform ?? 'general');
            $type = ucfirst($content->type ?? 'post');
            DB::table('approvals')->insert([
                'title' => $content->title . " ({$platform} / {$type})",
                'client_id' => $content->client_id,
                'content_id' => $contentId,
                'type' => $content->type ?? 'post',
                'status' => 'pending',
                'approval_stage' => 'first',
                'submitted_by' => auth()->id(),
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

    public function openDiscussion(int $contentId): void
    {
        $this->selectedContentId = $contentId;
        $this->showContentDiscussion = true;
        $this->showDayDetail = false;
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

    public function addContentComment(): void
    {
        if (! $this->commentText || ! $this->selectedContentId) {
            return;
        }

        $attachments = json_decode($this->commentAttachmentsJson, true) ?: [];

        Comment::create([
            'commentable_type' => Content::class,
            'commentable_id' => $this->selectedContentId,
            'user_id' => Auth::id(),
            'body' => $this->commentText,
            'attachments' => $attachments ?: null,
        ]);

        app(ActivityLogger::class)->record(
            Auth::user(),
            "Commented on content #{$this->selectedContentId}"
        );

        $this->commentText = '';
        $this->commentAttachments = [];
        $this->dispatch('tiptap-set-content', name: 'calComment', html: '');
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

        return $q->limit(30)->get()->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->name,
            'url' => $f->getUrl(),
            'type' => $f->type,
            'size_label' => $f->size_readable,
        ])->toArray();
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
        $this->caption = '';
        $this->hashtags = '';
        $this->referenceFile = '';
        $this->formAttachments = [];
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
            <div class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.250ms="search"
                    placeholder="Search content..."
                    class="form-input pl-10 focus:ring-0"
                />
                <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            </div>
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
                        {{ Carbon::createFromDate($currentYear, $currentMonth, 1)->format('F Y') }}
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
                $calendarDays = $this->getCalendarDays();
                $today = now()->format('Y-m-d');
                $monthDays = $this->getCalendarDays();
            @endphp
            <div class="grid grid-cols-7">
                @foreach ($monthDays as $day)
                    @php
                        $dateStr = $day->format('Y-m-d');
                        $isToday = $dateStr === $today;
                        $isWeekend = in_array($day->dayOfWeek, [0, 6]);
                        $isOtherMonth = $day->month !== $currentMonth;
                        $dayContent = $this->getContentForDay($dateStr);
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
                                {{ $day->format('j') }}
                            </span>
                            @if (count($dayContent) > 0)
                                <span
                                    class="text-[9px] font-bold {{ $isToday ? 'text-[var(--brand)]' : 'text-gray-400' }} bg-gray-100 rounded-full px-1.5 py-0.5 leading-none"
                                    >{{ count($dayContent) }}</span
                                >
                            @endif
                        </div>
                        <div class="space-y-0.5" @click.stop>
                            @foreach (array_slice($dayContent, 0, 2) as $item)
                                <div
                                    class="cal-event {{ $item->platform }}"
                                    wire:click.stop="editContent({{ $item->id }})"
                                    title="{{ $item->title }} ({{ ucfirst($item->platform) }})"
                                >
                                    {{ Str::limit($item->title, 14) }}
                                </div>
                            @endforeach
                            @if (count($dayContent) > 2)
                                <div class="text-[9px] font-medium text-gray-400 pl-1">
                                    +{{ count($dayContent) - 2 }} more
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
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($listContent as $item)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <span
                                        class="text-gray-600"
                                        >{{ \Carbon\Carbon::parse($item->date)->format('M d, Y') }}</span
                                    >
                                </td>
                                <td>
                                    <span class="font-semibold text-gray-900">{{ $item->title }}</span>
                                </td>
                                <td>
                                    <span class="text-gray-600">{{ $item->client_name ?? '-' }}</span>
                                </td>
                                <td>
                                    <span
                                        class="badge badge-{{ $item->platform }}"
                                        >{{ ucfirst($item->platform) }}</span
                                    >
                                </td>
                                <td>
                                    <span class="badge badge-{{ $item->type }}">{{ ucfirst($item->type) }}</span>
                                </td>
                                <td>
                                    <span
                                        class="badge badge-{{ $item->status }}"
                                        >{{ str_replace('-', ' ', ucfirst($item->status)) }}</span
                                    >
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @if (in_array($item->status, ['draft', 'scripting', 'revision']))
                                            @php
                                                $btnClass = $item->status === 'draft' ? 'btn-ghost' : 'btn-primary';
                                                $btnIcon = match($item->status) { 'draft' => 'fa-arrow-right', 'scripting' => 'fa-paper-plane', 'revision' => 'fa-redo', default => 'fa-arrow-right' };
                                                $btnLabel = match($item->status) { 'draft' => 'Script', 'scripting' => 'Submit', 'revision' => 'Resubmit', default => '' };
                                            @endphp
                                            <button
                                                wire:click="submitForApproval({{ $item->id }})"
                                                class="btn btn-sm {{ $btnClass }} py-1 px-2 text-xs"
                                            >
                                                <i class="fas {{ $btnIcon }} mr-1"></i> {{ $btnLabel }}
                                            </button>
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
                                                ><i class="fas fa-lock mr-1"></i>Published</span
                                            >
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
            <div class="modal-box max-w-2xl max-h-[90vh]" x-on:click.stop>
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

                <div class="modal-body overflow-y-auto max-h-[calc(90vh-80px)]">
                    <form wire:submit="save">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
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
                                <span wire:error="title" class="text-red-500 text-xs mt-1 block"></span>
                            </div>

                            <div>
                                <label class="form-label">Client</label>
                                <select wire:model="formClientId" class="form-select">
                                    <option value="">Internal / Own Company</option>
                                    @foreach ($clients as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <p class="text-[11px] text-gray-400 mt-1">Leave as Internal for own company content</p>
                            </div>

                            <div>
                                <label class="form-label">Date <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="formDate" class="form-input" />
                                @error ('formDate')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                                <span wire:error="formDate" class="text-red-500 text-xs mt-1 block"></span>
                            </div>

                            <div>
                                <label class="form-label"
                                    >Due Date <span class="text-gray-400 text-xs">(optional)</span></label
                                >
                                <input type="date" wire:model="formDueDate" class="form-input" />
                                <p class="text-[11px] text-gray-400 mt-1">When content must be completed by</p>
                            </div>

                            <div>
                                <label class="form-label">Status <span class="text-red-500">*</span></label>
                                <select wire:model="formStatus" class="form-select">
                                    @foreach (self::STATUSES as $s)
                                        <option value="{{ $s }}">{{ str_replace('-', ' ', ucfirst($s)) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="form-label"
                                    >Reference File
                                    <span class="text-gray-400 font-normal">(optional URL/name)</span></label
                                >
                                <input
                                    type="text"
                                    wire:model="referenceFile"
                                    class="form-input"
                                    placeholder="File name or URL"
                                />
                            </div>

                            <div class="md:col-span-2">
                                <label class="form-label">Attachments</label>
                                <x-file-picker :clientId="$formClientId" wire="formAttachments" />
                            </div>

                            {{-- Platforms Multi-Select --}}
                            <div class="md:col-span-2">
                                <label class="form-label">Platforms <span class="text-red-500">*</span></label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (self::PLATFORMS as $p)
                                        <label
                                            class="inline-flex items-center gap-2 rounded-lg border {{ in_array($p, $formPlatforms) ? 'border-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : 'border-gray-200 bg-white' }} px-3 py-2 cursor-pointer transition-colors hover:border-gray-300"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:model="formPlatforms"
                                                value="{{ $p }}"
                                                class="rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]"
                                            />
                                            <span class="badge badge-{{ $p }} text-[10px]">{{ ucfirst($p) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error ('formPlatforms')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Types Multi-Select --}}
                            <div class="md:col-span-2">
                                <label class="form-label">Content Types <span class="text-red-500">*</span></label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (self::TYPES as $t)
                                        <label
                                            class="inline-flex items-center gap-2 rounded-lg border {{ in_array($t, $formTypes) ? 'border-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : 'border-gray-200 bg-white' }} px-3 py-2 cursor-pointer transition-colors hover:border-gray-300"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:model="formTypes"
                                                value="{{ $t }}"
                                                class="rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]"
                                            />
                                            <span class="badge badge-{{ $t }} text-[10px]">{{ ucfirst($t) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error ('formTypes')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="md:col-span-2">
                                <label class="form-label">Caption</label>
                                <textarea
                                    wire:model="caption"
                                    class="form-textarea"
                                    rows="3"
                                    placeholder="Write your caption..."
                                ></textarea>
                            </div>

                            <div class="md:col-span-2">
                                <label class="form-label">Hashtags</label>
                                <textarea
                                    wire:model="hashtags"
                                    class="form-textarea"
                                    rows="2"
                                    placeholder="#hashtag1 #hashtag2"
                                ></textarea>
                            </div>
                        </div>

                        @if (!$editingId && count($formPlatforms) > 0 && count($formTypes) > 0)
                            <div
                                class="mt-3 rounded-lg bg-blue-50 border border-blue-100 px-4 py-2.5 text-xs text-blue-700"
                            >
                                <i class="fas fa-info-circle mr-1"></i>
                                This will create
                                <strong>{{ count($formPlatforms) * count($formTypes) }}</strong> content item(s) ({{ count($formPlatforms) }} platform(s)
                                × {{ count($formTypes) }} type(s))
                            </div>
                        @endif

                        <div class="mt-6 flex items-center justify-end gap-3 border-t border-gray-100 pt-5">
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
                                {{ $detailDate ? $detailDate->format('l, F j, Y') : '' }}
                            </h3>
                            <p class="text-xs text-gray-400 mt-0.5">{{ count($detailItems) }} {{ Str::plural('item', count($detailItems)) }}</p>
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
                            <p class="text-sm font-medium text-gray-400">No content scheduled</p>
                            <p class="text-xs text-gray-300 mt-1">Create content for this day</p>
                        </div>
                    @else
                        <div class="divide-y divide-gray-50">
                            @foreach ($detailItems as $item)
                                @php
                                $links = $contentLinks[$item->id] ?? null;
                                $workflow = $links['workflow'] ?? null;
                                $approval = $links['approval'] ?? null;
                                $clientName = null;
                                if ($item->client_id) {
                                    $c = \App\Models\Client::find($item->client_id);
                                    $clientName = $c?->name;
                                }
                            @endphp
                                <div
                                    class="flex items-start gap-3 px-6 py-3.5 {{ $item->status !== 'published' ? 'hover:bg-gray-50/50 cursor-pointer' : '' }} transition-colors"
                                    @if ($item->status !== 'published') wire:click="editContent({{ $item->id }})" @endif
                                >
                                    {{-- Platform icon --}}
                                    <div
                                        class="w-8 h-8 rounded-lg bg-{{ $item->platform }}-500/10 flex items-center justify-center flex-shrink-0 mt-0.5"
                                    >
                                        <i
                                            class="fas fa-{{ $platformIcons[$item->platform] ?? 'globe' }} text-{{ $item->platform }}-500 text-xs"
                                        ></i>
                                    </div>

                                    {{-- Content info --}}
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
                                            <span class="capitalize">{{ $item->type }}</span>
                                            @if ($workflow)
                                                <span>·</span>
                                                <span
                                                    class="text-indigo-500 font-medium capitalize"
                                                    >{{ str_replace('-', ' ', $workflow->stage) }}</span
                                                >
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Right side: action or status indicator --}}
                                    <div class="flex-shrink-0 ml-2 flex items-center gap-1.5">
                                        @if ($item->status !== 'published')
                                            <button
                                                wire:click.stop="openDiscussion({{ $item->id }})"
                                                class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition"
                                                title="Discussion"
                                            >
                                                <i class="fas fa-comments text-[11px]"></i>
                                            </button>
                                        @endif
                                        @if (in_array($item->status, ['draft', 'scripting', 'revision']))
                                            @php
                                            $ddBtnIcon = match($item->status) { 'draft' => 'fa-arrow-right', 'scripting' => 'fa-paper-plane', 'revision' => 'fa-redo', default => 'fa-arrow-right' };
                                            $ddBtnLabel = match($item->status) { 'draft' => 'Script', 'scripting' => 'Submit', 'revision' => 'Resubmit', default => '' };
                                        @endphp
                                            <button
                                                wire:click.stop="submitForApproval({{ $item->id }})"
                                                class="text-[11px] px-2.5 py-1 rounded-lg {{ $item->status === 'draft' ? 'bg-gray-100 text-gray-600 hover:bg-gray-200' : 'bg-[var(--brand)] text-white hover:opacity-90' }} font-medium transition"
                                            >
                                                <i class="fas {{ $ddBtnIcon }} mr-1 text-[9px]"></i>{{ $ddBtnLabel }}
                                            </button>
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
                                    </div>
                                </div>
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
        <div
            class="modal-overlay z-50"
            wire:click.self="$set('showContentDiscussion', false)"
            x-on:keydown.escape.window="$wire.set('showContentDiscussion', false)"
        >
            <div class="modal-box max-w-lg" x-on:click.stop>
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900 truncate pr-2">
                        <i class="fas fa-comments text-[var(--brand)] mr-2"></i>
                        {{ $discContent->title ?? 'Discussion' }}
                    </h3>
                    <button
                        wire:click="$set('showContentDiscussion', false)"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors flex-shrink-0"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <div class="modal-body space-y-4">
                    {{-- Content info --}}
                    <div class="flex items-center gap-2 text-xs text-gray-500">
                        @if ($discContent->client)
                            <span class="text-gray-600">{{ $discContent->client->name }}</span>
                            <span>·</span>
                        @endif
                        <span class="capitalize">{{ $discContent->type }}</span>
                        <span>·</span>
                        <span class="capitalize">{{ str_replace('-', ' ', $discContent->status) }}</span>
                    </div>

                    {{-- Comment list --}}
                    <div class="space-y-3 max-h-60 overflow-y-auto">
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
                            <p class="text-xs text-gray-400 text-center py-3">No comments yet. Start the discussion.</p>
                        @endforelse
                    </div>

                    {{-- Comment form --}}
                    <div class="border-t border-gray-100 pt-3">
                        <x-tiptap-editor wire="commentText" name="calComment" placeholder="Add a comment..." />
                        <div class="flex items-center justify-between mt-2">
                            <x-file-picker
                                :clientId="$discContent->client_id"
                                wire="commentAttachments"
                                wire-json="commentAttachmentsJson"
                            />
                            <input type="hidden" wire:model="commentAttachmentsJson" />
                            <button
                                wire:click="addContentComment"
                                wire:loading.attr="disabled"
                                wire:target="addContentComment"
                                class="btn btn-primary btn-sm"
                                :disabled="!$wire.commentText"
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
    @endif

    @script
        <script>
            document.addEventListener('livewire:initialized', () => {
                Livewire.on('contentUpdated', () => {});
            });
        </script>
    @endscript
</div>
