<?php

use Livewire\Volt\Component;

new class extends Component
{
    public string $status = '';
    public array $cycle = [];
    public string $entityType = '';
    public int $entityId = 0;
    public string $column = 'status';

    public function mount(string $status, array $cycle, string $entityType = '', int $entityId = 0, string $column = 'status'): void
    {
        $this->status = $status;
        $this->cycle = $cycle;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->column = $column;
    }

    public function cycle(): void
    {
        $currentIndex = array_search($this->status, $this->cycle);
        $nextIndex = ($currentIndex !== false) ? ($currentIndex + 1) % count($this->cycle) : 0;
        $this->status = $this->cycle[$nextIndex];

        if ($this->entityType && $this->entityId) {
            try {
                \Illuminate\Support\Facades\DB::table($this->entityType)
                    ->where('id', $this->entityId)
                    ->update([$this->column => $this->status]);
            } catch (\Exception $e) {
                // table may not exist yet
            }
        }

        $this->dispatch('statusUpdated', entityType: $this->entityType, entityId: $this->entityId, status: $this->status);
    }

    public function getBadgeClassProperty(): string
    {
        return match($this->status) {
            'todo', 'draft', 'inactive' => 'bg-gray-100 text-gray-600',
            'in-progress', 'scripting', 'active', 'pending' => 'bg-blue-100 text-blue-700',
            'completed', 'approved', 'published', 'paid' => 'bg-green-100 text-green-700',
            'overdue', 'rejected' => 'bg-red-100 text-red-700',
            'revision', 'review', 'in-review' => 'bg-orange-100 text-orange-700',
            'scheduled' => 'brand-bg-light brand-text-dark',
            default => 'bg-gray-100 text-gray-600',
        };
    }

    public function getLabelProperty(): string
    {
        return ucwords(str_replace('-', ' ', $this->status));
    }
}; ?>

<div
    wire:click="cycle"
    @class ([
        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold cursor-pointer transition-all hover:shadow-sm select-none',
        $this->badgeClass,
    ])
    title="Click to change status"
>
    <span>{{ $this->label }}</span>
    <svg class="w-3 h-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
</div>
