@php
    $users = is_string($usersJson) ? json_decode($usersJson, true) : $usersJson;
    $users = is_array($users) ? $users : [];
@endphp

<div
    x-data="{
        open: false,
        search: '',
        users: {{ json_encode($users) }},
        selected: @entangle($livewireProp).live,
        
        get filtered() {
            if (!this.search) return this.users;
            const q = this.search.toLowerCase();
            return this.users.filter(u => 
                (u.name || '').toLowerCase().includes(q) || 
                (u.role || '').toLowerCase().includes(q) || 
                (u.department || '').toLowerCase().includes(q)
            );
        },
        
        get grouped() {
            const groups = {};
            this.filtered.forEach(u => {
                const dept = u.department || 'Other';
                if (!groups[dept]) groups[dept] = [];
                groups[dept].push(u);
            });
            return groups;
        },
        
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.search = '';
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },
        
        toggleUser(id) {
            if (this.selected.includes(id)) {
                this.selected = this.selected.filter(x => x !== id);
            } else {
                this.selected = [...this.selected, id];
            }
        },
        
        getUser(id) {
            return this.users.find(u => u.id === id);
        },
        
        getName(id) {
            return this.getUser(id)?.name || id;
        },
        
        getInitials(id) {
            const name = this.getName(id);
            return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
        },
        
        selectAll() {
            this.selected = this.users.map(u => u.id);
        },
        
        clearAll() {
            this.selected = [];
        }
    }"
    class="relative"
    @click.away="open = false"
>
    {{-- Trigger Button --}}
    <div
        @click="toggle()"
        class="form-select cursor-pointer flex items-center flex-wrap gap-1.5 min-h-[42px] bg-white transition-all"
        :class="open ? 'border-[var(--brand)] ring-2 ring-[var(--brand)]/20' : ''"
    >
        <template x-if="selected.length === 0">
            <span class="text-gray-400 text-sm">Select staff...</span>
        </template>

        <template x-for="id in selected.slice(0, 3)" :key="id">
            <span
                class="inline-flex items-center gap-1.5 px-2 py-1 bg-[var(--brand)]/10 text-[var(--brand)] rounded-lg text-xs font-medium"
            >
                <span x-text="getName(id)"></span>
                <button type="button" @click.stop="toggleUser(id)" class="hover:bg-[var(--brand)]/20 rounded p-0.5">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </span>
        </template>

        <template x-if="selected.length > 3">
            <span
                class="px-2 py-1 bg-gray-100 rounded-lg text-xs text-gray-600"
                x-text="'+' + (selected.length - 3) + ' more'"
            ></span>
        </template>
    </div>

    {{-- Dropdown --}}
    <div
        x-show="open"
        x-transition
        class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-xl"
    >
        {{-- Search --}}
        <div class="p-3 border-b">
            <input
                type="text"
                x-model="search"
                x-ref="search"
                placeholder="Search staff..."
                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-[var(--brand)] focus:ring-1 focus:ring-[var(--brand)]"
            />
        </div>

        {{-- Actions --}}
        <div x-show="users.length > 0" class="flex items-center justify-between px-3 py-2 bg-gray-50 border-b text-xs">
            <button
                type="button"
                @click="selected.length === users.length ? clearAll() : selectAll()"
                class="font-medium hover:underline"
                :class="selected.length === users.length ? 'text-red-500' : 'text-[var(--brand)]'"
                x-text="selected.length === users.length ? '✕ Clear all' : '✓ Select all'"
            ></button>
            <span class="text-gray-500" x-text="selected.length + ' of ' + users.length"></span>
        </div>

        {{-- Users List --}}
        <div class="max-h-64 overflow-y-auto p-2">
            <template x-if="filtered.length === 0">
                <div class="p-8 text-center text-gray-400">
                    <svg class="w-12 h-12 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <p
                        class="text-sm font-medium"
                        x-text="users.length === 0 ? 'No staff available' : 'No results found'"
                    ></p>
                    <p class="text-xs mt-1">
                        <span x-show="users.length === 0">Add users to your system first</span>
                        <span x-show="users.length > 0">Try a different search</span>
                    </p>
                </div>
            </template>

            <template x-for="(userList, dept) in grouped" :key="dept">
                <div>
                    {{-- Department Header --}}
                    <div
                        class="px-2 py-1.5 text-[10px] font-bold text-gray-500 uppercase tracking-wide flex items-center gap-1"
                    >
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                        <span x-text="dept"></span>
                    </div>

                    {{-- Users --}}
                    <template x-for="user in userList" :key="user.id">
                        <div
                            @click="toggleUser(user.id)"
                            class="flex items-center gap-3 px-2 py-2 rounded-lg cursor-pointer hover:bg-gray-50 transition"
                            :class="selected.includes(user.id) ? 'bg-[var(--brand)]/5' : ''"
                        >
                            {{-- Avatar --}}
                            <div
                                class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0"
                                :class="selected.includes(user.id)
                                    ? 'bg-[var(--brand)]/20 text-[var(--brand)]'
                                    : 'bg-gray-100 text-gray-600'"
                            >
                                <template x-if="user.avatar">
                                    <img
                                        :src="user.avatar"
                                        :alt="user.name"
                                        class="w-full h-full rounded-full object-cover"
                                    />
                                </template>
                                <template x-if="!user.avatar">
                                    <span
                                        x-text="
                                            user.name
                                                .split(' ')
                                                .map((w) => w[0])
                                                .join('')
                                                .slice(0, 2)
                                                .toUpperCase()
                                        "
                                    ></span>
                                </template>
                            </div>

                            {{-- Name & Role --}}
                            <div class="flex-1 min-w-0">
                                <div
                                    class="text-sm truncate"
                                    :class="selected.includes(user.id)
                                        ? 'text-[var(--brand)] font-medium'
                                        : 'text-gray-900'"
                                    x-text="user.name"
                                ></div>
                                <div class="text-xs text-gray-500 truncate" x-show="user.role || user.department">
                                    <span x-text="user.role"></span>
                                    <span x-show="user.role && user.department"> · </span>
                                    <span x-text="user.department"></span>
                                </div>
                            </div>

                            {{-- Checkmark --}}
                            <svg
                                x-show="selected.includes(user.id)"
                                x-transition
                                class="w-5 h-5 text-[var(--brand)] flex-shrink-0"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        {{-- Done Button --}}
        <div class="border-t bg-gray-50 p-3">
            <button
                type="button"
                @click="open = false"
                class="w-full px-4 py-2 bg-[var(--brand)] text-white rounded-lg font-medium hover:bg-[var(--brand-dark)] transition-colors flex items-center justify-center gap-2"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span x-text="selected.length > 0 ? 'Done (' + selected.length + ' selected)' : 'Done'"></span>
            </button>
        </div>
    </div>
</div>

<style>
    [x-cloak] {
        display: none !important;
    }
</style>
