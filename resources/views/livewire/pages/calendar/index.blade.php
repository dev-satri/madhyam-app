<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
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

    private array $contentByDate = [];

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

    public function openForm(?string $date = null): void
    {
        $this->resetForm();
        $this->selectedDate = $date ?? now()->format('Y-m-d');
        $this->formDate = $this->selectedDate;
        $this->showForm = true;
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
                    class="form-input pl-10"
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
                        wire:click="openForm('{{ $dateStr }}')"
                        wire:loading.class="opacity-50"
                    >
                        <div class="flex items-center justify-between mb-1">
                            <span
                                class="text-[11px] font-semibold {{ $isToday ? 'bg-[var(--brand)] text-white w-5 h-5 rounded-full flex items-center justify-center' : ($isOtherMonth ? 'text-gray-300' : 'text-gray-600') }}"
                            >
                                {{ $day->format('j') }}
                            </span>
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
                                            wire:click="deleteContent({{ $item->id }})"
                                            wire:confirm="Are you sure you want to delete this content?"
                                            class="btn btn-icon btn-ghost"
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
                            </div>

                            <div>
                                <label class="form-label">Date <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="formDate" class="form-input" />
                                @error ('formDate')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
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
