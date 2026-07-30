@props ([
    'placeholder' => 'Search...',
    'wire' => null,
    'model' => null,
    'debounce' => '250ms',
    'compact' => false,
])

@php
    $wireModel = $wire ?? $model;
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }}>
    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center justify-center w-9">
        <i class="fas fa-search text-gray-400 {{ $compact ? 'text-[11px]' : 'text-xs' }}"></i>
    </div>
    <input
        type="search"
        @if ($wireModel) wire:model.live.debounce.{{ $debounce }}="{{ $wireModel }}" @endif
        placeholder="{{ $placeholder }}"
        class="{{ $compact ? 'form-input-sm' : 'form-input' }} w-full"
        style="padding-left: 2.25rem"
        {{ $attributes->except(['class', 'wire', 'model', 'debounce', 'compact', 'placeholder']) }}
    />
</div>
