@props ([
    'model' => null,
    'name' => null,
    'id' => null,
    'label' => null,
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
])

@php
    $isBs = \App\Support\NepaliDate::isBs();
    $inputId = $id ?? $name ?? $attributes->get('id', 'date-input-' . uniqid());
    $inputName = $name ?? $attributes->get('name', '');
    $rawValue = '';
    if ($model && isset($__livewire) && property_exists($__livewire, $model)) {
        $rawValue = $__livewire->{$model} ?? '';
    }
@endphp

@if ($isBs)
    <div
        class="space-y-1"
        x-data="bsDatePicker({
    modelValue: '{{ $rawValue }}',
    wireModel: '{{ $model }}',
})"
    >
        @if ($label)
            <label for="{{ $inputId }}" class="form-label">
                {{ $label }}
                @if ($required)
                    <span class="text-red-500">*</span>
                @endif
            </label>
        @endif
        <div class="relative">
            <input
                type="text"
                x-model="bsDisplayValue"
                readonly
                @click="togglePicker()"
                class="form-input cursor-pointer"
                placeholder="Select BS date"
                {{ $disabled ? 'disabled' : '' }}
                {{ $required ? 'required' : '' }}
                id="{{ $inputId }}"
            />
            <input type="hidden" name="{{ $inputName }}" x-model="adValue" {{ $attributes }} />

            <div
                x-show="showPicker"
                @click.outside="showPicker = false"
                x-transition
                class="absolute z-[60] mt-1 bg-white border border-gray-200 rounded-xl shadow-lg p-3 w-72"
            >
                <div class="flex items-center justify-between mb-2">
                    <button type="button" @click="prevMonth()" class="btn btn-ghost btn-sm">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                    <span
                        class="text-sm font-bold text-gray-900"
                        x-text="bsMonthNames[currentBsMonth - 1] + ' ' + currentBsYear"
                    ></span>
                    <button type="button" @click="nextMonth()" class="btn btn-ghost btn-sm">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                </div>
                <div class="grid grid-cols-7 gap-0.5 text-center">
                    <template x-for="d in ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']">
                        <div class="text-[10px] font-bold text-gray-400 py-1" x-text="d"></div>
                    </template>
                    <template x-for="(day, idx) in bsDays" :key="idx">
                        <button
                            type="button"
                            @click="selectDay(day)"
                            :disabled="day.pad || !day.d"
                            :class="{
                                'bg-[var(--brand)] text-white rounded-full': day.d && bsValue === day.bs,
                                'text-gray-300 cursor-not-allowed': day.pad,
                                'hover:bg-gray-100 cursor-pointer': !day.pad && day.d && bsValue !== day.bs,
                                'text-gray-700': !day.pad && day.d
                            }"
                            class="w-8 h-8 flex items-center justify-center text-xs rounded-lg transition-colors"
                            x-text="day.d || ''"
                        ></button>
                    </template>
                </div>
                <button type="button" @click="showPicker = false" class="mt-2 w-full btn btn-secondary btn-sm text-xs">
                    Close
                </button>
            </div>
        </div>
    </div>
@else
    <div class="space-y-1">
        @if ($label)
            <label for="{{ $inputId }}" class="form-label">
                {{ $label }}
                @if ($required)
                    <span class="text-red-500">*</span>
                @endif
            </label>
        @endif
        <input
            type="date"
            wire:model="{{ $model }}"
            id="{{ $inputId }}"
            name="{{ $inputName }}"
            class="form-input"
            placeholder="{{ $placeholder }}"
            {{ $disabled ? 'disabled' : '' }}
            {{ $required ? 'required' : '' }}
            {{ $attributes }}
        />
    </div>
@endif
