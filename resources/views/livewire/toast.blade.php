<?php

use Livewire\Volt\Component;

new class extends Component
{
    public array $items = [];

    public function show(string $message, string $type = 'success'): void
    {
        $this->items[] = [
            'id' => uniqid(),
            'message' => $message,
            'type' => $type,
            'exiting' => false,
        ];

        $id = end($this->items)['id'];
        $this->js("setTimeout(() => { const el = document.querySelector('[data-toast-id=\\'' + '{$id}' + '\\']'); if(el){ el.style.animation='slideOutRight 0.3s ease-in forwards'; setTimeout(()=>el.remove(),300); } }, 3000)");
    }

    public function remove(string $id): void
    {
        $this->items = array_values(array_filter($this->items, fn($t) => $t['id'] !== $id));
    }

    public function clear(): void
    {
        $this->items = [];
    }
}; ?>

<div class="pointer-events-none fixed top-5 right-5 z-[100] flex flex-col gap-2" aria-live="polite" aria-atomic="true" role="status">
    @foreach ($items as $toast)
        <div
            data-toast-id="{{ $toast['id'] }}"
            wire:key="{{ $toast['id'] }}"
            @class ([
                'pointer-events-auto flex items-center gap-3 min-w-[280px] max-w-[380px] px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium',
                'bg-green-600' => $toast['type'] === 'success',
                'bg-red-600' => $toast['type'] === 'error',
                'bg-yellow-500 text-gray-900' => $toast['type'] === 'warning',
                'bg-blue-600' => $toast['type'] === 'info',
            ])
            style="animation: slideInRight 0.25s ease-out"
        >
            @if ($toast['type'] === 'success')
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            @elseif ($toast['type'] === 'error')
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            @elseif ($toast['type'] === 'warning')
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            @else
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            @endif
            <span class="flex-1">{{ $toast['message'] }}</span>
            <button
                wire:click="remove('{{ $toast['id'] }}')"
                class="shrink-0 opacity-70 hover:opacity-100 transition-opacity"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>
    @endforeach
</div>
