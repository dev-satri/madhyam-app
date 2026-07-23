<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\PackageService;

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
    public int $formClientId = 0;
    public string $formDate = '';
    public array $formPlatforms = [];
    public array $formTypes = [];
    public string $formStatus = 'draft';
    public string $caption = '';
    public string $hashtags = '';
    public string $referenceFile = '';

    public array $clients = [];

    public const PLATFORMS = ['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'linkedin'];
    public const TYPES = ['reel', 'post', 'story', 'video', 'carousel', 'blog'];
    public const STATUSES = ['draft', 'scripting', 'in-review', 'scheduled', 'published'];

    public array $contentByDate = [];

    public function mount(): void
    {
        $this->currentMonth = (int) now()->month;
        $this->currentYear = (int) now()->year;
        $this->clients = DB::table('clients')->where('status', 'active')->orderBy('name')->get()->toArray();
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
        $this->currentMonth = (int) now()->month;
        $this->currentYear = (int) now()->year;
        $this->loadMonthContent();
    }

    public function loadMonthContent(): void
    {
        $start = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->startOfWeek(Carbon::SUNDAY);
        $end = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $query = DB::table('contents')
            ->join('clients', 'contents.client_id', '=', 'clients.id')
            ->whereBetween('contents.date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->select('contents.*', 'clients.name as client_name');

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
        if (!$this->selectedDate) return [];
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

    public function openForm(?string $date = null): void
    {
        $this->resetForm();
        $this->selectedDate = $date ?? now()->format('Y-m-d');
        $this->formDate = $this->selectedDate;
        $this->showForm = true;
        $this->showDayDetail = false;
    }

    public function editContent(int $id): void
    {
        $content = DB::table('contents')->where('id', $id)->first();
        if (!$content) return;

        $this->editingId = $id;
        $this->title = $content->title;
        $this->formClientId = (int) $content->client_id;
        $this->formDate = $content->date instanceof Carbon ? $content->date->format('Y-m-d') : $content->date;
        $this->formPlatforms = $content->platform ? [$content->platform] : [];
        $this->formTypes = $content->type ? [$content->type] : [];
        $this->formStatus = $content->status;
        $this->caption = $content->caption ?? '';
        $this->hashtags = $content->hashtags ?? '';
        $this->referenceFile = $content->reference_file ?? '';
        $this->showForm = true;
        $this->showDayDetail = false;
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'formClientId' => 'required|integer',
            'formDate' => 'required|date',
            'formPlatforms' => 'required|array|min:1',
            'formTypes' => 'required|array|min:1',
            'formStatus' => 'required|string|in:draft,scripting,in-review,scheduled,published',
        ]);

        if ($this->editingId) {
            DB::table('contents')->where('id', $this->editingId)->update([
                'title' => $this->title,
                'client_id' => $this->formClientId,
                'date' => $this->formDate,
                'platform' => $this->formPlatforms[0] ?? 'instagram',
                'type' => $this->formTypes[0] ?? 'post',
                'status' => $this->formStatus,
                'caption' => $this->caption,
                'hashtags' => $this->hashtags,
                'reference_file' => $this->referenceFile,
                'updated_at' => now(),
            ]);
            $this->dispatch('toast', message: 'Content updated successfully', type: 'success');
        } else {
            $count = 0;
            foreach ($this->formPlatforms as $platform) {
                foreach ($this->formTypes as $type) {
                    DB::table('contents')->insert([
                        'title' => $this->title,
                        'client_id' => $this->formClientId,
                        'date' => $this->formDate,
                        'platform' => $platform,
                        'type' => $type,
                        'status' => $this->formStatus,
                        'caption' => $this->caption,
                        'hashtags' => $this->hashtags,
                        'reference_file' => $this->referenceFile,
                        'created_by' => auth()->id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $count++;
                    // Track package usage
                    PackageService::recordContent($this->formClientId, $this->formStatus === 'published' ? 'published' : 'created');
                }
            }
            $this->dispatch('toast', message: "Created {$count} content item(s)", type: 'success');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function deleteContent(int $id): void
    {
        DB::table('contents')->where('id', $id)->delete();
        $this->dispatch('toast', message: 'Content deleted successfully', type: 'success');
    }

    public function getStats(): array
    {
        $query = DB::table('contents');

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
            ->join('clients', 'contents.client_id', '=', 'clients.id')
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

    private function resetForm(): void
    {
        $this->editingId = 0;
        $this->title = '';
        $this->formClientId = 0;
        $this->formDate = '';
        $this->formPlatforms = [];
        $this->formTypes = [];
        $this->formStatus = 'draft';
        $this->caption = '';
        $this->hashtags = '';
        $this->referenceFile = '';
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
                            @if(count($dayContent) > 0)
                                <span class="text-[9px] font-bold {{ $isToday ? 'text-[var(--brand)]' : 'text-gray-400' }} bg-gray-100 rounded-full px-1.5 py-0.5 leading-none">{{ count($dayContent) }}</span>
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
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
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
                                <label class="form-label">Client <span class="text-red-500">*</span></label>
                                <select wire:model="formClientId" class="form-select">
                                    <option value="0">Select Client</option>
                                    @foreach ($clients as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                @error ('formClientId')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                                <span wire:error="formClientId" class="text-red-500 text-xs mt-1 block"></span>
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
                                <label class="form-label">Status <span class="text-red-500">*</span></label>
                                <select wire:model="formStatus" class="form-select">
                                    @foreach (self::STATUSES as $s)
                                        <option value="{{ $s }}">{{ str_replace('-', ' ', ucfirst($s)) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Reference File</label>
                                <input
                                    type="text"
                                    wire:model="referenceFile"
                                    class="form-input"
                                    placeholder="File name or URL"
                                />
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
        @endphp
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" x-data="{ open: true }" x-show="open" x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="open = false; $wire.set('showDayDetail', false)"></div>
            <div class="relative bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl border border-gray-200 w-full sm:max-w-lg max-h-[90vh] flex flex-col mx-0 sm:mx-4 overflow-hidden z-10" x-show="open" x-transition:enter="ease-out duration-200" x-transition:enter-start="translate-y-8 sm:translate-y-0 sm:scale-95" x-transition:enter-end="translate-y-0 sm:scale-100" @click.away="open = false; $wire.set('showDayDetail', false)">
                {{-- Header --}}
                <div class="flex-shrink-0 px-5 py-4 border-b border-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[var(--brand)]/10 flex items-center justify-center">
                                <i class="fas fa-calendar-alt text-[var(--brand)]"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-900">{{ $detailDate ? $detailDate->format('l, F j, Y') : '' }}</h3>
                                <p class="text-xs text-gray-500 mt-0.5">{{ count($detailItems) }} content item(s) scheduled</p>
                            </div>
                        </div>
                        <button @click="open = false; $wire.set('showDayDetail', false)" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition">
                            <i class="fas fa-times text-sm"></i>
                        </button>
                    </div>
                </div>

                {{-- Platform Summary --}}
                @if (count($platformSummary) > 0)
                    <div class="flex-shrink-0 px-5 pt-4 pb-2">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($platformSummary as $platform => $count)
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold
                                    {{ $platform === 'instagram' ? 'bg-pink-50 text-pink-700' : '' }}
                                    {{ $platform === 'facebook' ? 'bg-blue-50 text-blue-700' : '' }}
                                    {{ $platform === 'tiktok' ? 'bg-gray-900 text-white' : '' }}
                                    {{ $platform === 'youtube' ? 'bg-red-50 text-red-700' : '' }}
                                    {{ !in_array($platform, ['instagram','facebook','tiktok','youtube']) ? 'bg-gray-100 text-gray-700' : '' }}">
                                    @if ($platform === 'instagram')
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                                    @elseif ($platform === 'facebook')
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                    @elseif ($platform === 'tiktok')
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1v-3.51a6.37 6.37 0 00-.79-.05A6.34 6.34 0 003.15 15.2a6.34 6.34 0 0010.86 4.46v-7.12a8.16 8.16 0 005.58 2.18v-3.45a4.85 4.85 0 01-3.77-1.59h-.23z"/></svg>
                                    @elseif ($platform === 'youtube')
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                                    @else
                                        <i class="fas fa-globe text-gray-500" style="font-size:14px"></i>
                                    @endif
                                    {{ ucfirst($platform) }} ({{ $count }})
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Content Items --}}
                <div class="flex-1 overflow-y-auto px-5 py-3">
                    @if (count($detailItems) === 0)
                        <div class="flex flex-col items-center justify-center py-12 text-center">
                            <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mb-4">
                                <i class="fas fa-file-alt text-2xl text-gray-300"></i>
                            </div>
                            <p class="text-gray-400 font-medium">No content scheduled</p>
                            <p class="text-gray-300 text-sm mt-1">Click the button below to create content for this day</p>
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach ($detailItems as $item)
                                @php
                                    $statusColors = [
                                        'draft' => 'bg-gray-100 text-gray-700',
                                        'scripting' => 'bg-purple-100 text-purple-700',
                                        'in-review' => 'bg-amber-100 text-amber-700',
                                        'revision' => 'bg-orange-100 text-orange-700',
                                        'scheduled' => 'bg-blue-100 text-blue-700',
                                        'published' => 'bg-green-100 text-green-700',
                                        'completed' => 'bg-emerald-100 text-emerald-700',
                                    ];
                                    $statusIcons = [
                                        'draft' => 'pencil',
                                        'scripting' => 'document-text',
                                        'in-review' => 'eye',
                                        'revision' => 'arrow-uturn-left',
                                        'scheduled' => 'clock',
                                        'published' => 'globe-alt',
                                        'completed' => 'check-circle',
                                    ];
                                    $platformIcons = [
                                        'instagram' => 'camera',
                                        'facebook' => 'globe',
                                        'tiktok' => 'music',
                                        'youtube' => 'play',
                                    ];
                                @endphp
                                <div class="group flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-gray-200 hover:shadow-sm transition-all duration-150">
                                    <div class="w-9 h-9 rounded-lg bg-{{ $item->platform }}-500/10 flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-{{ $platformIcons[$item->platform] ?? 'globe' }} text-{{ $item->platform }}-500" style="font-size:14px"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <h4 class="text-sm font-semibold text-gray-800 truncate">{{ $item->title }}</h4>
                                        </div>
                                        <div class="flex items-center gap-1.5 mt-1">
                                            @if ($item->client_id)
                                                @php $client = \App\Models\Client::find($item->client_id); @endphp
                                                @if ($client)
                                                    <span class="text-xs text-gray-500">{{ $client->name }}</span>
                                                    <span class="text-gray-300">·</span>
                                                @endif
                                            @endif
                                            <span class="text-xs text-gray-400 capitalize">{{ $item->type }}</span>
                                            @if ($item->needs_approval)
                                                <span class="text-xs px-1.5 py-0.5 rounded bg-amber-50 text-amber-600 font-medium">Approval</span>
                                            @endif
                                        </div>
                                    </div>
                                    <span class="text-[11px] font-semibold px-2 py-0.5 rounded-lg {{ $statusColors[$item->status] ?? 'bg-gray-100 text-gray-700' }}">
                                        {{ str_replace('-', ' ', ucfirst($item->status)) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Footer --}}
                <div class="flex-shrink-0 px-5 py-4 border-t border-gray-100 flex items-center justify-between">
                    <button @click="open = false; $wire.set('showDayDetail', false)" class="btn btn-secondary">
                        Close
                    </button>
                    <button wire:click="openForm('{{ $selectedDate }}')" class="btn btn-primary" onclick="setTimeout(() => $wire.set('showDayDetail', false), 100)">
                        <i class="fas fa-plus text-xs"></i>
                        Add Content
                    </button>
                </div>
            </div>
        </div>
    @endif

    @script
        <script>
            document.addEventListener('livewire:initialized', () => {
                Livewire.on('contentUpdated', () => {
                    initCharts();
                });
            });

            function initCharts() {
                const brand = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#4f46e5';
                const platformData = @js ($platformData);
                const typeData = @js ($typeData);

                const platformColors = {
                    instagram: '#E1306C',
                    facebook: '#1877F2',
                    tiktok: '#000000',
                    youtube: '#FF0000',
                    twitter: '#1DA1F2',
                    linkedin: '#0A66C2'
                };

                const typeColors = {
                    reel: '#8b5cf6',
                    post: '#3b82f6',
                    story: '#ec4899',
                    video: '#ef4444',
                    carousel: '#f59e0b',
                    blog: '#22c55e'
                };

                // Destroy existing charts
                const existingPlatform = Chart.getChart('platformDistChart');
                if (existingPlatform) existingPlatform.destroy();
                const existingType = Chart.getChart('typeMixChart');
                if (existingType) existingType.destroy();

                // Platform Distribution Bar
                const pCtx = document.getElementById('platformDistChart');
                if (pCtx && Object.keys(platformData).length > 0) {
                    const labels = Object.keys(platformData).map((k) => k.charAt(0).toUpperCase() + k.slice(1));
                    const data = Object.values(platformData);
                    const colors = Object.keys(platformData).map((k) => platformColors[k] || brand);
                    new Chart(pCtx, {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{ label: 'Content', data, backgroundColor: colors, borderRadius: 6, barThickness: 28 }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } },
                                x: { grid: { display: false } }
                            }
                        }
                    });
                }

                // Content Type Mix Donut
                const tCtx = document.getElementById('typeMixChart');
                if (tCtx && Object.keys(typeData).length > 0) {
                    const labels = Object.keys(typeData).map((k) => k.charAt(0).toUpperCase() + k.slice(1));
                    const data = Object.values(typeData);
                    const colors = Object.keys(typeData).map((k) => typeColors[k] || brand);
                    new Chart(tCtx, {
                        type: 'doughnut',
                        data: {
                            labels,
                            datasets: [{ data, backgroundColor: colors, borderWidth: 0 }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '65%',
                            plugins: {
                                legend: { position: 'right', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }
                            }
                        }
                    });
                }
            }

            document.addEventListener('livewire:load', () => {
                setTimeout(initCharts, 100);
            });

            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(initCharts, 200);
            });
        </script>
    @endscript
</div>
