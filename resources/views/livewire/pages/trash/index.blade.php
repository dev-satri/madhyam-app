<?php

use App\Models\Trash;
use App\Services\TrashService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $typeFilter = '';
    public string $search = '';

    public function mount(): void
    {
        abort_unless(in_array(Auth::user()->role, ['super-admin', 'admin']), 403);
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function getItemsProperty()
    {
        $query = Trash::query();

        if ($this->typeFilter) {
            $query->forModel($this->typeFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('trashable_type', 'like', "%{$this->search}%")
                  ->orWhereJsonContains('model_data', ['title' => $this->search])
                  ->orWhereJsonContains('model_data', ['name' => $this->search]);
            });
        }

        return $query->with('deleter')->latest()->paginate(15);
    }

    public function getTypeCountsProperty(): array
    {
        return app(TrashService::class)->getTrashCounts();
    }

    public function getTrashModelTypes(): array
    {
        return [
            'all' => 'All',
            App\Models\Task::class => 'Tasks',
            App\Models\File::class => 'Files',
            App\Models\Folder::class => 'Folders',
            App\Models\Content::class => 'Content',
            App\Models\Workflow::class => 'Workflows',
            App\Models\Approval::class => 'Approvals',
            App\Models\Client::class => 'Clients',
            App\Models\Invoice::class => 'Invoices',
            App\Models\Complaint::class => 'Complaints',
            App\Models\Leave::class => 'Leaves',
            App\Models\Expense::class => 'Expenses',
            App\Models\Salary::class => 'Salaries',
            App\Models\OvertimeLog::class => 'Overtime',
            App\Models\CustomRole::class => 'Roles',
            App\Models\TaskComment::class => 'Task Comments',
            App\Models\ApprovalComment::class => 'Approval Comments',
            App\Models\ComplaintReply::class => 'Complaint Replies',
            App\Models\InvoicePayment::class => 'Invoice Payments',
        ];
    }

    public function performRestore(int $id): void
    {
        app(TrashService::class)->restore($id);
        $this->dispatch('toast', message: 'Item restored successfully', type: 'success');
    }

    public function performDelete(int $id): void
    {
        app(TrashService::class)->forceDelete($id);
        $this->dispatch('toast', message: 'Item permanently deleted', type: 'success');
    }

    #[On('confirm-resolved')]
    public function onConfirmResolved(string $action, array $params = []): void
    {
        if ($action !== '' && method_exists($this, $action)) {
            $this->{$action}(...$params);
        }
    }

    public function getModelLabel(Trash $item): string
    {
        return $item->display_label;
    }

    public function getModelIcon(Trash $item): string
    {
        $model = class_basename($item->trashable_type);

        return match ($model) {
            'Task' => 'fa-tasks',
            'File' => 'fa-file',
            'Folder' => 'fa-folder',
            'Content' => 'fa-calendar-alt',
            'Workflow' => 'fa-columns',
            'Approval' => 'fa-check-double',
            'Client' => 'fa-users',
            'Invoice' => 'fa-receipt',
            'Complaint' => 'fa-exclamation-circle',
            'Leave' => 'fa-calendar-minus',
            'Expense' => 'fa-receipt',
            'Salary' => 'fa-money-bill-wave',
            'OvertimeLog' => 'fa-clock',
            'CustomRole' => 'fa-user-tag',
            'TaskComment' => 'fa-comment',
            'ApprovalComment' => 'fa-comment',
            'ComplaintReply' => 'fa-reply',
            'InvoicePayment' => 'fa-credit-card',
            default => 'fa-trash',
        };
    }

    public function getModelColor(Trash $item): string
    {
        $model = class_basename($item->trashable_type);

        return match ($model) {
            'Task' => 'text-blue-500',
            'File' => 'text-purple-500',
            'Folder' => 'text-amber-500',
            'Content' => 'text-green-500',
            'Workflow' => 'brand-text',
            'Approval' => 'text-teal-500',
            'Client' => 'text-cyan-500',
            'Invoice' => 'text-emerald-500',
            'Complaint' => 'text-red-500',
            'Leave' => 'text-orange-500',
            'Expense' => 'text-pink-500',
            'Salary' => 'text-lime-500',
            'OvertimeLog' => 'text-violet-500',
            'CustomRole' => 'text-gray-500',
            default => 'text-gray-400',
        };
    }
}; ?>

<div>
    {{-- ========== HEADER ========== --}}
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-900">Trash</h1>
            <p class="text-sm text-gray-500 mt-1">Deleted items are kept for 7 days before permanent removal</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-400">{{ $this->items->total() }} item(s) in trash</span>
        </div>
    </div>

    {{-- ========== FILTER TABS ========== --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @php $types = $this->getTrashModelTypes(); @endphp
        @foreach ($types as $key => $label)
            @php
                $count = $key === 'all'
                    ? $this->items->total()
                    : collect($this->typeCounts)->firstWhere('full_type', $key)['count'] ?? 0;
            @endphp
            <button
                wire:click="$set('typeFilter', '{{ $key === 'all' ? '' : $key }}')"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors
                    {{ ($key === 'all' && $this->typeFilter === '') || $this->typeFilter === $key
                        ? 'bg-gray-900 text-white'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
            >
                {{ $label }}
                @if ($count > 0)
                    <span class="rounded-full bg-white/20 px-1.5 text-[10px]">{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ========== SEARCH ========== --}}
    <div class="mb-4">
        <x-search-input wire="search" placeholder="Search trash..." class="max-w-sm" />
    </div>

    {{-- ========== TRASH TABLE ========== --}}
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Deleted By</th>
                        <th>Deleted At</th>
                        <th>Expires In</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->items as $item)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100"
                                    >
                                        <i
                                            class="fas {{ $this->getModelIcon($item) }} {{ $this->getModelColor($item) }} text-sm"
                                        ></i>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900 text-sm">{{ $this->getModelLabel($item) }}</p>
                                        <p class="text-[11px] text-gray-400">ID: {{ $item->trashable_id }}</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span
                                    class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600"
                                >
                                    {{ class_basename($item->trashable_type) }}
                                </span>
                            </td>
                            <td>
                                @if ($item->status)
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $item->status_badge_class }}"
                                    >
                                        {{ $item->status_label }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="text-gray-600 text-sm">
                                {{ $item->deleter?->name ?? ($item->deleted_by_type === 'client' ? 'Client' : 'System') }}
                            </td>
                            <td class="text-gray-600 text-sm">{{ $item->created_at->diffForHumans() }}</td>
                            <td>
                                @php $days = $item->days_remaining; @endphp
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold
                                        {{ $days <= 1 ? 'bg-red-100 text-red-700' : ($days <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700') }}"
                                >
                                    {{ $days }} day{{ $days !== 1 ? 's' : '' }}
                                </span>
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="$dispatch('open-confirm', { title: 'Restore Item?', message: 'Are you sure you want to restore this item? It will be returned to its original location.', type: 'info', action: 'performRestore', params: [{{ $item->id }}], confirmLabel: 'Restore' })"
                                        class="btn btn-icon btn-ghost"
                                        title="Restore"
                                    >
                                        <i class="fas fa-undo text-gray-400 hover:text-green-500"></i>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="$dispatch('open-confirm', { title: 'Delete Permanently?', message: 'This action cannot be undone. The item will be permanently removed from the database.', type: 'danger', action: 'performDelete', params: [{{ $item->id }}], confirmLabel: 'Delete Forever' })"
                                        class="btn btn-icon btn-ghost"
                                        title="Delete Permanently"
                                    >
                                        <i class="fas fa-times text-gray-400 hover:text-red-500"></i>
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
                                        <i class="fas fa-trash-alt text-2xl text-gray-300"></i>
                                    </div>
                                    <p class="text-gray-500 font-medium text-sm">Trash is empty</p>
                                    <p class="text-gray-400 text-xs mt-1">Deleted items will appear here</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->items->hasPages())
            <div class="border-t border-gray-100 px-4 py-3">{{ $this->items->links() }}</div>
        @endif
    </div>
</div>
