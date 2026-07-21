<?php

use Livewire\Volt\Component;

new class extends Component
{
    public bool $show = false;
    public string $title = '';
    public string $size = 'md';
    public $slot;

    protected array $sizeClasses = [
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
    ];

    public function open(string $title, string $size = 'md'): void
    {
        $this->title = $title;
        $this->size = in_array($size, ['md', 'lg', 'xl']) ? $size : 'md';
        $this->show = true;
        $this->dispatch('modalOpened');
    }

    public function close(): void
    {
        $this->show = false;
        $this->dispatch('modalClosed');
    }

    public function getModalSizeClassProperty(): string
    {
        return $this->sizeClasses[$this->size] ?? 'max-w-md';
    }
}; ?>

<div x-data="{ open: @js($show) }" x-on:open-modal.window="$wire.open($event.detail.title ?? $event.detail, $event.detail.size ?? 'md')" x-effect="open = $wire.show" x-cloak>
    @if ($show)
        <div
            class="modal-overlay fixed inset-0 z-[70] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-on:click.self="$wire.close()"
            x-on:keydown.escape.window="$wire.close()"
        >
            <div
                class="modal-box w-full {{ $this->modalSizeClass }} rounded-2xl bg-white shadow-2xl"
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95 translate-y-4"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                x-transition:leave-end="opacity-0 scale-95 translate-y-4"
            >
                <div class="modal-header flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 class="text-base font-bold text-gray-900">{{ $title }}</h3>
                    <button wire:click="close" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="modal-body overflow-y-auto px-6 py-5">
                    {{ $slot }}
                </div>
            </div>
        </div>
    @endif
</div>
