<?php

use Livewire\Volt\Component;

new class extends Component
{
    public bool $show = false;
    public string $title = '';
    public string $message = '';
    public string $type = 'danger';
    public bool $confirmed = false;

    public function confirm(string $title, string $message, string $type = 'danger'): void
    {
        $this->title = $title;
        $this->message = $message;
        $this->type = $type;
        $this->confirmed = false;
        $this->show = true;
    }

    public function resolve(bool $confirmed): void
    {
        $this->confirmed = $confirmed;
        $this->show = false;
        $this->dispatch('confirmResolved', confirmed: $confirmed);
    }
}; ?>

<div>
    @if ($show)
        <div
            class="confirm-overlay fixed inset-0 z-[80] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
            x-data
            x-on:keydown.escape.window="$wire.resolve(false)"
        >
            <div
                class="confirm-box w-full max-w-sm rounded-2xl bg-white p-7 shadow-2xl text-center"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
            >
                @if ($type === 'danger')
                    <div
                        class="confirm-icon danger mx-auto mb-4 flex h-13 w-13 items-center justify-center rounded-full bg-red-100 text-red-600"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                @elseif ($type === 'warning')
                    <div
                        class="confirm-icon warning mx-auto mb-4 flex h-13 w-13 items-center justify-center rounded-full bg-yellow-100 text-yellow-600"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                @else
                    <div
                        class="confirm-icon info mx-auto mb-4 flex h-13 w-13 items-center justify-center rounded-full bg-blue-100 text-blue-600"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                @endif

                <h3 class="mb-2 text-lg font-bold text-gray-900">{{ $title }}</h3>
                <p class="mb-6 text-sm text-gray-500">{{ $message }}</p>

                <div class="flex gap-3">
                    <button
                        wire:click="resolve(false)"
                        class="flex-1 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="resolve(true)"
                        @if ($type === 'danger')
                            class="flex-1 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 transition-colors"
                        @elseif ($type === 'warning')
                            class="flex-1 rounded-xl bg-yellow-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-yellow-600 transition-colors"
                        @else
                            class="flex-1 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition-colors"
                        @endif
                    >
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
