<?php

use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $show = false;
    public string $title = '';
    public string $message = '';
    public string $type = 'danger';
    public string $confirmLabel = 'Delete';
    public string $cancelLabel = 'Cancel';
    public string $action = '';
    public array $params = [];

    #[On('open-confirm')]
    public function open(
        string $title,
        string $message,
        string $type = 'danger',
        string $action = '',
        array $params = [],
        string $confirmLabel = '',
        string $cancelLabel = 'Cancel',
    ): void {
        $this->title = $title;
        $this->message = $message;
        $this->type = in_array($type, ['danger', 'warning', 'info'], true) ? $type : 'danger';
        $this->action = $action;
        $this->params = array_values($params);
        $this->cancelLabel = $cancelLabel !== '' ? $cancelLabel : 'Cancel';
        $this->confirmLabel = $confirmLabel !== ''
            ? $confirmLabel
            : match ($this->type) {
                'danger' => 'Delete',
                'warning' => 'Continue',
                default => 'Confirm',
            };
        $this->show = true;
    }

    public function cancel(): void
    {
        $this->reset(['show', 'action', 'params']);
    }

    public function accept(): void
    {
        $action = $this->action;
        $params = $this->params;
        $this->reset(['show', 'action', 'params']);
        if ($action !== '') {
            $this->dispatch('confirm-resolved', action: $action, params: $params);
        }
    }
}; ?>

<div>
    @if ($show)
        <div
            class="confirm-overlay"
            x-data
            x-on:keydown.escape.window="$wire.cancel()"
            wire:click.self="cancel"
        >
            <div
                class="confirm-box"
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-dialog-title"
                aria-describedby="confirm-dialog-message"
                x-trap.noscroll="true"
                x-init="$nextTick(() => $refs.confirmBtn?.focus())"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
            >
                <div class="confirm-icon {{ $type }}" aria-hidden="true">
                    @if ($type === 'danger')
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    @elseif ($type === 'warning')
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    @else
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    @endif
                </div>

                <h3 id="confirm-dialog-title" class="mb-2 text-lg font-bold text-gray-900">{{ $title }}</h3>
                <p id="confirm-dialog-message" class="mb-6 text-sm text-gray-500">{{ $message }}</p>

                <div class="flex gap-3">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="flex-1 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
                    >
                        {{ $cancelLabel }}
                    </button>
                    <button
                        type="button"
                        x-ref="confirmBtn"
                        wire:click="accept"
                        wire:loading.attr="disabled"
                        @class([
                            'flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition-colors disabled:opacity-70',
                            'bg-red-600 hover:bg-red-700' => $type === 'danger',
                            'bg-yellow-500 hover:bg-yellow-600' => $type === 'warning',
                            'bg-blue-600 hover:bg-blue-700' => $type === 'info',
                        ])
                    >
                        @if ($type === 'danger')
                            <i class="fas fa-trash text-xs mr-1"></i>
                        @endif
                        {{ $confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
