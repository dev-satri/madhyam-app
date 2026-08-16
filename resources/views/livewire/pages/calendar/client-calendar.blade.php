<?php

use Anuzpandey\LaravelNepaliDate\Exceptions\InvalidDateException;
use Anuzpandey\LaravelNepaliDate\LaravelNepaliDate;
use App\Models\ClientAccount;
use App\Models\Comment;
use App\Models\Content;
use App\Models\User;
use App\Notifications\ClientContentRequestNotification;
use App\Notifications\ContentCommentNotification;
use App\Support\ContentTags;
use App\Support\NepaliDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $viewMode = 'month';

    public int $currentMonth;

    public int $currentYear;

    public string $search = '';

    public string $platformFilter = '';

    public string $statusFilter = '';

    public bool $showDayDetail = false;

    public ?string $selectedDate = null;

    public ?int $selectedContentId = null;

    public bool $showContentDetail = false;

    public bool $showContentPreview = false;

    public string $commentText = '';

    public array $contentByDate = [];

    // Content Form Properties
    public bool $showContentForm = false;

    public string $formTitle = '';

    public array $formTypes = [];

    public array $formPlatforms = [];

    public string $formDate = '';

    public string $formCaption = '';

    public string $formHashtags = '';

    public string $formReferenceFile = '';

    public $formUploadedFiles = [];

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
        $this->loadMonthContent();
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
        $account = Auth::guard('client')->user();
        if (! $account) {
            $this->contentByDate = [];

            return;
        }

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

        // Load content for this client only
        $query = DB::table('contents')
            ->whereNull('deleted_at')
            ->where('client_id', $account->client_id)
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->select('contents.*');

        // Apply filters
        if ($this->search) {
            $query->where('contents.title', 'like', "%{$this->search}%");
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

        $allContent = $query->orderBy('contents.date')->get();

        $this->contentByDate = [];
        foreach ($allContent as $item) {
            $item->_type = 'content';
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

    public function viewContent(int $id): void
    {
        $account = Auth::guard('client')->user();
        if (! $account) {
            return;
        }

        $content = Content::where('id', $id)
            ->where('client_id', $account->client_id)
            ->first();

        if ($content) {
            $this->selectedContentId = $id;
            $this->showContentPreview = true;
            $this->showDayDetail = false;
        }
    }

    public function closeContentPreview(): void
    {
        $this->showContentPreview = false;
        $this->selectedContentId = null;
        $this->commentText = '';
    }

    public function closeContentDetail(): void
    {
        $this->showContentDetail = false;
        $this->selectedContentId = null;
        $this->commentText = '';
    }

    public function getSelectedContent()
    {
        if (! $this->selectedContentId) {
            return null;
        }

        $account = Auth::guard('client')->user();
        if (! $account) {
            return null;
        }

        return Content::where('id', $this->selectedContentId)
            ->where('client_id', $account->client_id)
            ->first();
    }

    public function getContentComments()
    {
        if (! $this->selectedContentId) {
            return collect();
        }

        // Use Content::class to match admin calendar
        return Comment::where('commentable_type', Content::class)
            ->where('commentable_id', $this->selectedContentId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getContentAttachments(): array
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

        // Get content's own attachments
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

        // Resolve file IDs to URLs
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

    public function addComment(): void
    {
        $this->validate(['commentText' => 'required|string|max:2000']);

        $account = Auth::guard('client')->user();
        if (! $account || ! $this->selectedContentId) {
            return;
        }

        // Verify the content belongs to this client
        $content = Content::where('id', $this->selectedContentId)
            ->where('client_id', $account->client_id)
            ->first();

        if (! $content) {
            $this->dispatch('toast', message: 'Content not found', type: 'error');

            return;
        }

        $comment = Comment::create([
            'commentable_type' => Content::class,
            'commentable_id' => $this->selectedContentId,
            'user_id' => $account->id,
            'user_type' => ClientAccount::class,
            'body' => $this->commentText,
        ]);

        // Notify assigned staff + admins/managers
        $recipientIds = collect();

        if ($content->assignee) {
            $assigneeIds = is_array($content->assignee) ? $content->assignee : json_decode($content->assignee, true);
            if (is_array($assigneeIds)) {
                $recipientIds = $recipientIds->merge($assigneeIds);
            }
        }

        $adminIds = User::whereIn('role', ['admin', 'manager'])
            ->where('status', 'active')
            ->pluck('id');
        $recipientIds = $recipientIds->merge($adminIds)->unique();

        if ($recipientIds->isNotEmpty()) {
            $notification = new ContentCommentNotification($comment, $content, $account);
            $recipients = User::whereIn('id', $recipientIds)->where('status', 'active')->get();
            foreach ($recipients as $recipient) {
                $recipient->notify($notification);
            }
        }

        $this->commentText = '';
        $this->dispatch('toast', message: 'Comment added successfully', type: 'success');
    }

    public function getStats(): array
    {
        $account = Auth::guard('client')->user();
        if (! $account) {
            return [];
        }

        $query = DB::table('contents')
            ->whereNull('deleted_at')
            ->where('client_id', $account->client_id);

        return [
            'total' => $query->count(),
            'published' => (clone $query)->where('status', 'published')->count(),
            'in_review' => (clone $query)->where('status', 'in-review')->count(),
            'draft' => (clone $query)->where('status', 'draft')->count(),
        ];
    }

    public function openContentForm(?string $date = null): void
    {
        $this->formTitle = '';
        $this->formTypes = [];
        $this->formPlatforms = [];
        $this->formDate = $date ?? ''; // Pre-fill with selected date or empty
        $this->formCaption = '';
        $this->formHashtags = '';
        $this->formReferenceFile = '';
        $this->formUploadedFiles = [];
        $this->showContentForm = true;
        $this->showDayDetail = false;
    }

    public function submitContentRequest(): void
    {
        $this->validate([
            'formTitle' => 'required|string|max:255',
            'formTypes' => 'required|array|min:1',
            'formPlatforms' => 'required|array|min:1',
            'formDate' => 'required|date|after_or_equal:today',
            'formCaption' => 'required|string',
            'formUploadedFiles.*' => 'nullable|file|max:10240', // 10MB max per file
        ]);

        $account = Auth::guard('client')->user();
        if (! $account) {
            return;
        }

        // Handle file uploads
        $attachments = [];
        if (! empty($this->formUploadedFiles)) {
            foreach ($this->formUploadedFiles as $file) {
                if ($file) {
                    $path = $file->store('client-uploads', 'public');
                    $attachments[] = [
                        'id' => uniqid(),
                        'name' => $file->getClientOriginalName(),
                        'url' => Storage::url($path),
                        'type' => str_starts_with($file->getMimeType(), 'image/') ? 'image' : 'file',
                        'size' => $file->getSize(),
                    ];
                }
            }
        }

        // Normalize tags
        $platforms = ContentTags::normalize($this->formPlatforms, 'platform');
        $types = ContentTags::normalize($this->formTypes, 'type');

        $content = Content::create([
            'title' => $this->formTitle,
            'client_id' => $account->client_id,
            'submitted_by_client_id' => $account->id,
            'platform' => $platforms,
            'type' => $types,
            'date' => $this->formDate,
            'status' => 'draft',
            'caption' => $this->formCaption,
            'hashtags' => $this->formHashtags,
            'reference_file' => $this->formReferenceFile ?: null,
            'attachments' => ! empty($attachments) ? $attachments : null,
            'needs_approval' => false,
        ]);

        // Send notifications to managers and admins only (not super-admin)
        $notifiableUsers = User::query()
            ->where(function ($q) {
                $q->where('role', 'manager')
                    ->orWhere('role', 'admin');
            })
            ->get();

        foreach ($notifiableUsers as $user) {
            $user->notify(new ClientContentRequestNotification($content, $account));
        }

        $this->showContentForm = false;
        $this->loadMonthContent();
        $this->dispatch('toast', message: 'Content request submitted successfully!', type: 'success');
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-900">Content Calendar</h1>
            <p class="text-sm text-gray-500 mt-1">View your scheduled content and deliverables</p>
        </div>
        <button wire:click="openContentForm" class="btn btn-primary">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Request Content
        </button>
    </div>

    {{-- Filters Bar --}}
    <div class="mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 items-end">
        <div>
            <label class="form-label">Search</label>
            <x-search-input wire="search" placeholder="Search content..." />
        </div>
        <div>
            <label class="form-label">Platform</label>
            <select wire:model.live="platformFilter" class="form-select">
                <option value="">All Platforms</option>
                <option value="instagram">Instagram</option>
                <option value="facebook">Facebook</option>
                <option value="tiktok">TikTok</option>
                <option value="youtube">YouTube</option>
                <option value="twitter">Twitter</option>
                <option value="linkedin">LinkedIn</option>
            </select>
        </div>
        <div>
            <label class="form-label">Status</label>
            <select wire:model.live="statusFilter" class="form-select">
                <option value="">All Status</option>
                <option value="draft">Draft</option>
                <option value="scripting">Scripting</option>
                <option value="in-review">In Review</option>
                <option value="revision">Revision</option>
                <option value="published">Published</option>
            </select>
        </div>
    </div>

    {{-- Stats Row --}}
    @php $stats = $this->getStats(); @endphp
    <div class="mb-4 flex flex-wrap gap-2">
        @php
            $allStatuses = [
                'total' => ['icon' => 'fa-file-alt', 'color' => 'bg-gray-100 text-gray-600', 'label' => 'Total'],
                'published' => ['icon' => 'fa-check-circle', 'color' => 'bg-green-50 text-green-600', 'label' => 'Published'],
                'in_review' => ['icon' => 'fa-eye', 'color' => 'bg-amber-50 text-amber-600', 'label' => 'In Review'],
                'draft' => ['icon' => 'fa-pencil', 'color' => 'bg-gray-100 text-gray-600', 'label' => 'Draft'],
            ];
        @endphp
        @foreach ($allStatuses as $key => $info)
            <div
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold {{ $info['color'] }}"
            >
                <i class="fas {{ $info['icon'] }} text-[10px]"></i>
                {{ $info['label'] }}
                <span class="ml-0.5 bg-white/60 rounded-full px-1.5 py-0.5 text-[10px]">{{ $stats[$key] ?? 0 }}</span>
            </div>
        @endforeach
    </div>

    {{-- Type Legend --}}
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

    {{-- Calendar --}}
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        {{-- Month Navigation --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
            <div class="flex items-center gap-2">
                <button wire:click="prevMonth" class="btn btn-ghost btn-sm" title="Previous Month">
                    <i class="fas fa-chevron-left text-xs"></i>
                </button>
                <h3 class="text-sm font-bold text-gray-900 min-w-[160px] text-center">
                    @if (\App\Support\NepaliDate::isBs())
                        {{ \App\Support\NepaliDate::bsMonthName($this->currentMonth) }} {{ $this->currentYear }}
                    @else
                        {{ Carbon\Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->format('F Y') }}
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

        {{-- Calendar Grid --}}
        <div class="p-4">
            {{-- Day Headers --}}
            <div class="grid grid-cols-7 gap-2 mb-2">
                @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day)
                    <div class="text-center text-xs font-bold text-gray-500 uppercase tracking-wider py-2">
                        {{ $day }}
                    </div>
                @endforeach
            </div>

            {{-- Calendar Days --}}
            <div class="grid grid-cols-7 gap-2">
                @php 
                    $isBs = \App\Support\NepaliDate::isBs();
                    $days = $this->getCalendarDays();
                    $today = now()->format('Y-m-d');
                @endphp

                @foreach ($days as $day)
                    @php
                        if ($isBs) {
                            $dayNum = $day['bs_day'];
                            $date = $day['ad_date'];
                            $isCurrentMonth = $day['is_current_bs_month'];
                        } else {
                            $dayNum = $day->day;
                            $date = $day->format('Y-m-d');
                            $isCurrentMonth = $day->month === $this->currentMonth;
                        }
                        
                        $isToday = $date === $today;
                        $dayContent = $this->getContentForDay($date);
                        $hasContent = count($dayContent) > 0;
                    @endphp

                    <div
                        wire:click="openDayDetail('{{ $date }}')"
                        class="min-h-[100px] rounded-lg border transition-all cursor-pointer
                            {{ $isCurrentMonth ? 'bg-white border-gray-200 hover:border-[var(--brand)] hover:shadow-md' : 'bg-gray-50 border-gray-100 opacity-50' }}
                            {{ $isToday ? 'ring-2 ring-[var(--brand)] ring-offset-1' : '' }}"
                    >
                        <div class="p-2">
                            <div class="flex items-center justify-between mb-1">
                                <span
                                    class="text-sm font-bold {{ $isToday ? 'text-[var(--brand)]' : ($isCurrentMonth ? 'text-gray-900' : 'text-gray-400') }}"
                                >
                                    {{ $dayNum ?: '' }}
                                </span>
                                @if ($hasContent)
                                    <span
                                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-[var(--brand)] text-white"
                                    >
                                        {{ count($dayContent) }}
                                    </span>
                                @endif
                            </div>

                            @if ($hasContent && $isCurrentMonth)
                                <div class="space-y-1 mt-2">
                                    @foreach (array_slice($dayContent, 0, 3) as $content)
                                        @php
                                            $statusColor = match($content->status) {
                                                'published' => 'bg-green-500',
                                                'in-review' => 'bg-amber-500',
                                                'revision' => 'bg-orange-500',
                                                'scripting' => 'bg-purple-500',
                                                default => 'bg-gray-400'
                                            };
                                            $_types = \App\Support\ContentTags::normalize($content->type, 'type');
                                            $_primaryType = \App\Support\ContentTags::primary($_types, 'type');
                                            $typeColor = match($_primaryType) {
                                                'reel' => 'border-pink-500',
                                                'post' => 'border-blue-500',
                                                'story' => 'border-purple-500',
                                                'video' => 'border-red-500',
                                                'carousel' => 'border-amber-500',
                                                'blog' => 'border-green-500',
                                                'all' => 'border-indigo-500',
                                                default => 'border-gray-400'
                                            };
                                        @endphp
                                        <div
                                            class="flex items-center gap-1.5 text-xs p-1.5 rounded bg-gray-50 hover:bg-gray-100 border-l-2 {{ $typeColor }}"
                                            title="{{ \App\Support\ContentTags::label($_types, 'type') }}: {{ $content->title }}"
                                        >
                                            <div
                                                class="w-1.5 h-1.5 rounded-full {{ $statusColor }} flex-shrink-0"
                                            ></div>
                                            <span
                                                class="truncate flex-1 text-gray-700"
                                                >{{ Str::limit($content->title, 20) }}</span
                                            >
                                        </div>
                                    @endforeach
                                    @if (count($dayContent) > 3)
                                        <div class="text-[10px] text-gray-400 text-center py-0.5">
                                            +{{ count($dayContent) - 3 }} more
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Content Preview Modal --}}
    @if ($showContentPreview && $selectedContentId)
        @php 
            $previewContent = $this->getSelectedContent();
        @endphp
        @if ($previewContent)
            @php
                $_previewTypes = \App\Support\ContentTags::normalize($previewContent->type, 'type');
                $_previewPlatforms = \App\Support\ContentTags::normalize($previewContent->platform, 'platform');
                $_previewPrimaryType = \App\Support\ContentTags::primary($_previewTypes, 'type');
                $typeBadge = match($_previewPrimaryType) {
                    'reel'     => 'bg-pink-50 text-pink-700 border-pink-200',
                    'post'     => 'bg-blue-50 text-blue-700 border-blue-200',
                    'story'    => 'bg-purple-50 text-purple-700 border-purple-200',
                    'video'    => 'bg-red-50 text-red-700 border-red-200',
                    'carousel' => 'bg-amber-50 text-amber-700 border-amber-200',
                    'blog'     => 'bg-green-50 text-green-700 border-green-200',
                    default    => 'bg-gray-50 text-gray-600 border-gray-200',
                };
                $statusBadge = match($previewContent->status) {
                    'published'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'draft'      => 'bg-gray-50 text-gray-600 border-gray-200',
                    'scripting'  => 'bg-purple-50 text-purple-700 border-purple-200',
                    'in-review'  => 'bg-amber-50 text-amber-700 border-amber-200',
                    'revision'   => 'bg-orange-50 text-orange-700 border-orange-200',
                    default      => 'bg-gray-50 text-gray-600 border-gray-200',
                };
            @endphp
            <div
                class="modal-overlay z-50"
                wire:click.self="closeContentPreview"
                x-data
                x-on:keydown.escape.window="$wire.closeContentPreview()"
            >
                <div class="modal-box max-w-2xl" x-on:click.stop>
                    {{-- Header --}}
                    <div class="modal-header border-b border-gray-100 pb-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5 mb-1.5">
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $typeBadge }}"
                                >
                                    {{ \App\Support\ContentTags::label($_previewTypes, 'type') }}
                                </span>
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border {{ $statusBadge }}"
                                >
                                    {{ ucwords(str_replace('-', ' ', $previewContent->status)) }}
                                </span>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 leading-snug">{{ $previewContent->title }}</h3>
                        </div>
                        <button
                            wire:click="closeContentPreview"
                            class="btn btn-ghost btn-icon btn-sm"
                            aria-label="Close"
                        >
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div class="modal-body space-y-4 max-h-[70vh] overflow-y-auto">
                        {{-- Caption --}}
                        @if ($previewContent->caption)
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Caption</p>
                                <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $previewContent->caption }}</p>
                            </div>
                        @endif

                        {{-- Metadata --}}
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Platform</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5">
                                    <i class="fas fa-share-nodes text-gray-400"></i>
                                    {{ \App\Support\ContentTags::label($_previewPlatforms, 'platform') }}
                                </p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Date</p>
                                <p class="text-gray-800 text-xs flex items-center gap-1.5">
                                    <i class="fas fa-calendar-alt text-gray-400"></i>
                                    {{ $previewContent->date ? \App\Support\NepaliDate::display($previewContent->date) : '—' }}
                                </p>
                            </div>
                            @if ($previewContent->due_date)
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1">Due Date</p>
                                    <p class="text-gray-800 text-xs flex items-center gap-1.5">
                                        <i class="fas fa-clock text-gray-400"></i>
                                        {{ \App\Support\NepaliDate::display($previewContent->due_date) }}
                                    </p>
                                </div>
                            @endif
                        </div>

                        {{-- Hashtags --}}
                        @if ($previewContent->hashtags && trim($previewContent->hashtags) !== '')
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1.5">Hashtags</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (array_filter(array_map('trim', explode(' ', $previewContent->hashtags))) as $tag)
                                        @if ($tag !== '')
                                            <span
                                                class="inline-flex items-center rounded-md bg-gray-100 text-gray-700 px-2 py-0.5 text-xs"
                                            >
                                                <i class="fas fa-hashtag text-gray-400 text-[9px] mr-1"></i
                                                >{{ ltrim($tag, '#') }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Reference File --}}
                        @if ($previewContent->reference_file)
                            <div>
                                <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400 mb-1.5">Reference</p>
                                <a
                                    href="{{ $previewContent->reference_file }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-2 text-sm text-[var(--brand)] hover:underline font-medium"
                                >
                                    <i class="fas fa-external-link-alt text-xs"></i>
                                    View Reference Material
                                </a>
                            </div>
                        @endif

                        {{-- Attachments --}}
                        @php $contentAtts = $this->getContentAttachments(); @endphp
                        @if (count($contentAtts) > 0)
                            <div>
                                @include ('livewire.partials.attachment-display', ['attachments' => $contentAtts, 'label' => 'Attachments'])
                            </div>
                        @endif

                        {{-- Discussion --}}
                        <div class="border-t border-gray-100 pt-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fas fa-comments text-gray-400"></i> Discussion
                            </h4>

                            <div class="space-y-3 max-h-48 overflow-y-auto mb-3">
                                @php $comments = $this->getContentComments(); @endphp
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
                                <textarea
                                    wire:model="commentText"
                                    placeholder="Add a comment..."
                                    class="form-input w-full resize-none text-sm"
                                    rows="3"
                                ></textarea>
                                <div class="flex justify-end mt-2">
                                    <button
                                        wire:click="addComment"
                                        wire:loading.attr="disabled"
                                        class="btn btn-primary btn-sm"
                                    >
                                        <i
                                            class="fas fa-paper-plane text-xs"
                                            wire:loading.remove
                                            wire:target="addComment"
                                        ></i>
                                        <i
                                            class="fas fa-spinner fa-spin text-xs"
                                            wire:loading
                                            wire:target="addComment"
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

    {{-- Day Detail Modal --}}
    @if ($showDayDetail)
        @php
            $detailDate = $selectedDate ? \Carbon\Carbon::parse($selectedDate) : null;
            $detailItems = $this->getDayContent();
            
            $statusColors = [
                'draft' => 'bg-gray-100 text-gray-600',
                'scripting' => 'bg-purple-50 text-purple-700',
                'in-review' => 'bg-amber-50 text-amber-700',
                'revision' => 'bg-orange-50 text-orange-700',
                'published' => 'bg-emerald-50 text-emerald-700',
            ];
            $platformIcons = [
                'instagram' => 'camera',
                'facebook' => 'globe',
                'tiktok' => 'music',
                'youtube' => 'play',
                'twitter' => 'bird',
                'linkedin' => 'briefcase',
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
                        <div class="flex-1">
                            <h3 class="text-base font-bold text-gray-900">
                                {{ $detailDate ? \App\Support\NepaliDate::display($detailDate) : '' }}
                            </h3>
                            <p class="text-xs text-gray-400 mt-0.5">
                                @if (count($detailItems) > 0)
                                    {{ count($detailItems) }} {{ Str::plural('content', count($detailItems)) }} scheduled
                                @else
                                    No items scheduled
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                wire:click="openContentForm('{{ $selectedDate }}')"
                                class="btn btn-primary btn-sm"
                                title="Request content for this date"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                            </button>
                            <button
                                wire:click="$set('showDayDetail', false)"
                                class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition"
                            >
                                <i class="fas fa-times text-sm"></i>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Content List --}}
                <div class="flex-1 overflow-y-auto">
                    @if (count($detailItems) === 0)
                        <div class="flex flex-col items-center justify-center py-16 text-center px-6">
                            <div class="w-14 h-14 rounded-full bg-gray-50 flex items-center justify-center mb-3">
                                <i class="fas fa-calendar-plus text-xl text-gray-300"></i>
                            </div>
                            <p class="text-sm font-medium text-gray-400">Nothing scheduled</p>
                            <p class="text-xs text-gray-300 mt-1">No content planned for this day</p>
                        </div>
                    @else
                        <div class="divide-y divide-gray-50">
                            @foreach ($detailItems as $item)
                                @php
                                    $_platforms = \App\Support\ContentTags::normalize($item->platform, 'platform');
                                    $_primary = \App\Support\ContentTags::primary($_platforms, 'platform');
                                    $_typeLabel = \App\Support\ContentTags::label(\App\Support\ContentTags::normalize($item->type, 'type'), 'type');
                                @endphp
                                <div
                                    class="flex items-start gap-3 px-6 py-3.5 hover:bg-gray-50/50 cursor-pointer transition-colors"
                                    wire:click="viewContent({{ $item->id }})"
                                >
                                    <div
                                        class="w-8 h-8 rounded-lg bg-{{ $_primary }}-500/10 flex items-center justify-center flex-shrink-0 mt-0.5"
                                    >
                                        <i
                                            class="fas fa-{{ $platformIcons[$_primary] ?? 'globe' }} text-{{ $_primary }}-500 text-xs"
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
                                            <span>{{ $_typeLabel }}</span>
                                            @if ($item->caption)
                                                <span>·</span>
                                                <span class="truncate">{{ Str::limit($item->caption, 30) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0 ml-2">
                                        <i class="fas fa-chevron-right text-xs text-gray-300"></i>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Content Detail Modal --}}
    @if ($showContentDetail)
        @php $selectedContent = $this->getSelectedContent(); @endphp
        @if ($selectedContent)
            @php
                $platforms = \App\Support\ContentTags::normalize($selectedContent->platform, 'platform');
                $types = \App\Support\ContentTags::normalize($selectedContent->type, 'type');
                $statusBadge = match($selectedContent->status) {
                    'draft' => 'bg-gray-100 text-gray-600',
                    'scripting' => 'bg-purple-50 text-purple-700',
                    'in-review' => 'bg-amber-50 text-amber-700',
                    'revision' => 'bg-orange-50 text-orange-700',
                    'published' => 'bg-emerald-50 text-emerald-700',
                    default => 'bg-gray-100 text-gray-600'
                };
            @endphp
            <div
                class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm"
                wire:click.self="closeContentDetail"
                x-data
                x-on:keydown.escape.window="$wire.closeContentDetail()"
            >
                <div
                    class="relative bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:max-w-2xl max-h-[90vh] flex flex-col mx-0 sm:mx-4 overflow-hidden"
                >
                    {{-- Header --}}
                    <div class="flex-shrink-0 px-6 py-4 border-b border-gray-100 bg-white">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-base font-bold text-gray-900 line-clamp-2">
                                    {{ $selectedContent->title }}
                                </h3>
                                <div class="flex items-center gap-2 mt-2 text-xs text-gray-500">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span>{{ \App\Support\NepaliDate::display($selectedContent->date) }}</span>
                                    @if ($selectedContent->due_date)
                                        <span>·</span>
                                        <span
                                            >Due: {{ \App\Support\NepaliDate::display($selectedContent->due_date) }}</span
                                        >
                                    @endif
                                </div>
                            </div>
                            <button
                                wire:click="closeContentDetail"
                                class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition flex-shrink-0"
                            >
                                <i class="fas fa-times text-sm"></i>
                            </button>
                        </div>

                        {{-- Status & Tags --}}
                        <div class="flex flex-wrap items-center gap-2 mt-3">
                            <span class="text-[10px] font-semibold px-2 py-1 rounded-md {{ $statusBadge }}">
                                {{ str_replace('-', ' ', ucfirst($selectedContent->status)) }}
                            </span>
                            @foreach (\App\Support\ContentTags::expand($platforms, 'platform') as $platform)
                                <span class="text-[10px] font-medium px-2 py-1 rounded-md bg-gray-100 text-gray-600">
                                    {{ ucfirst($platform) }}
                                </span>
                            @endforeach
                            @foreach (\App\Support\ContentTags::expand($types, 'type') as $type)
                                <span class="text-[10px] font-medium px-2 py-1 rounded-md bg-gray-100 text-gray-600">
                                    {{ ucfirst($type) }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    {{-- Content Body --}}
                    <div class="flex-1 overflow-y-auto">
                        <div class="p-6 space-y-4">
                            {{-- Caption --}}
                            @if ($selectedContent->caption)
                                <div>
                                    <label
                                        class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 block"
                                        >Caption</label
                                    >
                                    <div class="p-4 rounded-lg bg-gray-50 border border-gray-100">
                                        <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ $selectedContent->caption }}</p>
                                    </div>
                                </div>
                            @endif

                            {{-- Hashtags --}}
                            @if ($selectedContent->hashtags)
                                <div>
                                    <label
                                        class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 block"
                                        >Hashtags</label
                                    >
                                    <div class="p-4 rounded-lg bg-blue-50 border border-blue-100">
                                        <p class="text-sm text-blue-700 font-medium">{{ $selectedContent->hashtags }}</p>
                                    </div>
                                </div>
                            @endif

                            {{-- Reference File --}}
                            @if ($selectedContent->reference_file)
                                <div>
                                    <label
                                        class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 block"
                                        >Reference</label
                                    >
                                    <a
                                        href="{{ $selectedContent->reference_file }}"
                                        target="_blank"
                                        class="inline-flex items-center gap-2 text-sm text-[var(--brand)] hover:underline font-medium"
                                    >
                                        <i class="fas fa-external-link-alt text-xs"></i>
                                        View Reference Material
                                    </a>
                                </div>
                            @endif

                            {{-- Attachments --}}
                            @if ($selectedContent->attachments && count($selectedContent->attachments) > 0)
                                <div>
                                    <label
                                        class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 block"
                                    >
                                        Attachments ({{ count($selectedContent->attachments) }})
                                    </label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        @foreach ($selectedContent->attachments as $att)
                                            <div
                                                class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 bg-white hover:shadow-sm transition"
                                            >
                                                <div
                                                    class="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0"
                                                >
                                                    <i class="fas fa-file text-gray-400 text-xs"></i>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-xs font-medium text-gray-700 truncate">{{ $att['name'] ?? 'Attachment' }}</p>
                                                    @if (isset($att['size']))
                                                        <p class="text-[10px] text-gray-400">{{ number_format($att['size'] / 1024, 1) }} KB</p>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Comments Section --}}
                            <div class="border-t border-gray-100 pt-4">
                                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">
                                    <i class="fas fa-comments mr-1"></i>Comments
                                </h4>

                                {{-- Add Comment --}}
                                <div class="mb-4">
                                    <textarea
                                        wire:model="commentText"
                                        placeholder="Add your feedback or questions..."
                                        class="form-input w-full resize-none text-sm"
                                        rows="3"
                                    ></textarea>
                                    <div class="flex justify-end mt-2">
                                        <button wire:click="addComment" class="btn btn-primary btn-sm">
                                            <i class="fas fa-paper-plane mr-1 text-xs"></i>Post
                                        </button>
                                    </div>
                                </div>

                                {{-- Comments List --}}
                                @php $comments = $this->getContentComments(); @endphp
                                @if ($comments->isEmpty())
                                    <div class="text-center py-8">
                                        <div
                                            class="w-12 h-12 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-2"
                                        >
                                            <i class="fas fa-comment-slash text-xl text-gray-300"></i>
                                        </div>
                                        <p class="text-xs text-gray-400">No comments yet</p>
                                    </div>
                                @else
                                    <div class="space-y-3">
                                        @foreach ($comments as $comment)
                                            <div class="flex gap-3 p-3 rounded-lg bg-gray-50">
                                                <div
                                                    class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 text-xs font-bold flex-shrink-0"
                                                >
                                                    {{ strtoupper(substr($comment->user->name ?? 'U', 0, 2)) }}
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span
                                                            class="text-xs font-semibold text-gray-900"
                                                            >{{ $comment->user->name ?? 'Unknown' }}</span
                                                        >
                                                        <span
                                                            class="text-[10px] text-gray-400"
                                                            >{{ $comment->created_at->diffForHumans() }}</span
                                                        >
                                                    </div>
                                                    <p class="text-sm text-gray-700 leading-relaxed">{{ $comment->body }}</p>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- Content Request Form Modal --}}
    @if ($showContentForm)
        <div class="modal-overlay z-50" x-data x-on:keydown.escape.window="$wire.set('showContentForm', false)">
            <div class="modal-box max-w-2xl" x-on:click.stop>
                {{-- Header --}}
                <div class="modal-header">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[var(--brand)]/10 flex items-center justify-center">
                            <svg class="w-5 h-5 text-[var(--brand)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Request Content</h3>
                            <p class="text-xs text-gray-500 mt-0.5">Tell us what you need</p>
                        </div>
                    </div>
                    <button
                        wire:click="$set('showContentForm', false)"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <div class="modal-body">
                    <form wire:submit.prevent="submitContentRequest" class="flex flex-col max-h-[70vh]">
                        <div class="flex-1 overflow-y-auto space-y-4 pr-1 -mr-1">
                            {{-- Title --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Content Title <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    wire:model="formTitle"
                                    class="form-input"
                                    placeholder="e.g., Summer Sale Campaign"
                                    required
                                />
                            </div>

                            {{-- Date --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Preferred Date <span class="text-red-500">*</span>
                                </label>
                                @if (\App\Support\NepaliDate::isBs())
                                    <x-date-input model="formDate" required />
                                @else
                                    <input
                                        type="date"
                                        wire:model="formDate"
                                        class="form-input"
                                        min="{{ now()->format('Y-m-d') }}"
                                        required
                                    />
                                @endif
                                @error ('formDate')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Content Type --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Content Type <span class="text-red-500">*</span>
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <label
                                        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 cursor-pointer transition-colors hover:border-gray-300 {{ in_array('all', $formTypes) ? 'border-[var(--brand)] bg-[var(--brand)] text-white' : 'border-gray-200 bg-white' }}"
                                    >
                                        <input
                                            type="checkbox"
                                            wire:model.live="formTypes"
                                            value="all"
                                            class="rounded border-gray-300 focus:ring-[var(--brand)] w-3.5 h-3.5 {{ in_array('all', $formTypes) ? 'text-white' : 'text-[var(--brand)]' }}"
                                        />
                                        <span class="text-[10px] font-bold uppercase tracking-wide whitespace-nowrap"
                                            >All</span
                                        >
                                    </label>
                                    @foreach (['reel', 'post', 'story', 'video', 'carousel', 'blog'] as $t)
                                        <label
                                            class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 cursor-pointer transition-colors hover:border-gray-300 {{ in_array($t, $formTypes) ? 'border-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : 'border-gray-200 bg-white' }}"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:model.live="formTypes"
                                                value="{{ $t }}"
                                                class="rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)] w-3.5 h-3.5"
                                            />
                                            <span class="badge badge-{{ $t }} text-[10px]">{{ ucfirst($t) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error ('formTypes')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Platform --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Platform <span class="text-red-500">*</span>
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <label
                                        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 cursor-pointer transition-colors hover:border-gray-300 {{ in_array('all', $formPlatforms) ? 'border-[var(--brand)] bg-[var(--brand)] text-white' : 'border-gray-200 bg-white' }}"
                                    >
                                        <input
                                            type="checkbox"
                                            wire:model.live="formPlatforms"
                                            value="all"
                                            class="rounded border-gray-300 focus:ring-[var(--brand)] w-3.5 h-3.5 {{ in_array('all', $formPlatforms) ? 'text-white' : 'text-[var(--brand)]' }}"
                                        />
                                        <span class="text-[10px] font-bold uppercase tracking-wide whitespace-nowrap"
                                            >All</span
                                        >
                                    </label>
                                    @foreach (['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'linkedin'] as $p)
                                        <label
                                            class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 cursor-pointer transition-colors hover:border-gray-300 {{ in_array($p, $formPlatforms) ? 'border-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : 'border-gray-200 bg-white' }}"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:model.live="formPlatforms"
                                                value="{{ $p }}"
                                                class="rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)] w-3.5 h-3.5"
                                            />
                                            <span class="badge badge-{{ $p }} text-[10px]">{{ ucfirst($p) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error ('formPlatforms')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Caption/Description --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Caption / Description <span class="text-red-500">*</span>
                                </label>
                                <textarea
                                    wire:model="formCaption"
                                    class="form-input resize-none"
                                    rows="3"
                                    placeholder="Describe your content idea, message, or any specific requirements..."
                                    required
                                ></textarea>
                                <p class="text-xs text-gray-500 mt-1">Be as detailed as possible</p>
                            </div>

                            {{-- Hashtags --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Hashtags</label>
                                <input
                                    type="text"
                                    wire:model="formHashtags"
                                    class="form-input"
                                    placeholder="#marketing #sale #summer"
                                />
                            </div>

                            {{-- Reference File --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Reference URL</label>
                                <input
                                    type="url"
                                    wire:model="formReferenceFile"
                                    class="form-input"
                                    placeholder="https://example.com/reference or Google Drive link"
                                />
                            </div>

                            {{-- Attachments Note --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Upload Files</label>
                                <div
                                    x-data="{ uploading: false, progress: 0 }"
                                    x-on:livewire-upload-start="uploading = true"
                                    x-on:livewire-upload-finish="uploading = false"
                                    x-on:livewire-upload-error="uploading = false"
                                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                                >
                                    <label
                                        class="flex flex-col items-center justify-center w-full rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-6 cursor-pointer hover:border-[var(--brand)] hover:bg-[var(--brand)]/5 transition"
                                    >
                                        <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"
                                            />
                                        </svg>
                                        <p class="mt-2 text-sm font-medium text-gray-700">Click to upload files</p>
                                        <p class="mt-1 text-xs text-gray-500">Images, PDFs, or any reference files</p>
                                        <input
                                            type="file"
                                            wire:model="formUploadedFiles"
                                            multiple
                                            class="hidden"
                                            accept="image/*,.pdf,.doc,.docx"
                                        />
                                    </label>

                                    <div x-show="uploading" class="mt-2">
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                class="bg-[var(--brand)] h-2 rounded-full transition-all"
                                                :style="`width: ${progress}%`"
                                            ></div>
                                        </div>
                                        <p class="text-xs text-gray-600 mt-1">Uploading... <span x-text="progress"></span>%</p>
                                    </div>

                                    @if (!empty($formUploadedFiles))
                                        <div class="mt-3 space-y-2">
                                            @foreach ($formUploadedFiles as $index => $file)
                                                <div
                                                    class="flex items-center justify-between p-2 bg-white rounded-lg border border-gray-200"
                                                >
                                                    <div class="flex items-center gap-2 flex-1 min-w-0">
                                                        <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                        </svg>
                                                        <span
                                                            class="text-sm text-gray-700 truncate"
                                                            >{{ $file->getClientOriginalName() }}</span
                                                        >
                                                        <span class="text-xs text-gray-500"
                                                            >({{ number_format($file->getSize() / 1024, 1) }} KB)</span
                                                        >
                                                    </div>
                                                    <button
                                                        type="button"
                                                        wire:click="$set('formUploadedFiles.{{ $index }}', null)"
                                                        class="text-red-500 hover:text-red-700 ml-2"
                                                    >
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-500 mt-2">Or add links in Reference URL above for cloud storage files</p>
                            </div>
                        </div>

                        {{-- Footer --}}
                        <div
                            class="flex items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4 mt-4 -mx-6 -mb-6"
                        >
                            <button type="button" wire:click="$set('showContentForm', false)" class="btn btn-secondary">
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="btn btn-primary"
                                wire:loading.attr="disabled"
                                wire:target="submitContentRequest"
                            >
                                <svg
                                    class="w-4 h-4 mr-1.5"
                                    wire:loading.remove
                                    wire:target="submitContentRequest"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"
                                    />
                                </svg>
                                <i
                                    class="fas fa-spinner fa-spin text-xs mr-1.5"
                                    wire:loading
                                    wire:target="submitContentRequest"
                                ></i>
                                <span wire:loading.remove wire:target="submitContentRequest">Submit Request</span>
                                <span wire:loading wire:target="submitContentRequest">Submitting...</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
