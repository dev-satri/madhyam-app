<div
    x-data="{ isBs: @json($dateFormat === 'BS') }"
    @dateFormatChanged.window="isBs = $event.detail.format === 'BS'"
    class="flex items-center"
>
    <button
        wire:click="toggle"
        class="relative inline-flex h-8 w-[72px] items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-[var(--brand)] focus:ring-offset-2"
        :class="isBs ? 'bg-[var(--brand)]' : 'bg-gray-300'"
        title="Toggle Date Format"
    >
        <span
            class="inline-flex items-center justify-center h-6 w-[30px] rounded-full bg-white shadow-md transform transition-transform duration-200 text-[10px] font-bold"
            :class="isBs ? 'translate-x-[38px]' : 'translate-x-[3px]'"
            x-text="isBs ? 'BS' : 'AD'"
            :style="isBs ? 'color: var(--brand)' : 'color: #6b7280'"
        ></span>
    </button>
    <span class="ml-2 text-xs font-medium text-gray-500 hidden sm:inline" x-text="isBs ? 'बि.सं.' : 'A.D.'"></span>
</div>
