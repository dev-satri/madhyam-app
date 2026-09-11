@props ([
    'clientId' => null,
    'wireClientId' => null,
    'multiple' => true,
    'wire' => 'formAttachments',
    'initial' => [],
])

@php
    $inputId = 'fp-' . md5($wire . $clientId);
    $initialAttached = is_string($initial) ? (json_decode($initial, true) ?: []) : ($initial ?: []);
@endphp

<div
    x-data="{
        inputId: @js($inputId),
        wireProp: @js($wire),
        multiple: @js($multiple),
        open: false,
        activeTab: 'files',
        fileViewMode: 'folders', // 'folders' or 'all'
        search: '',
        files: [],
        folders: [],
        breadcrumbs: [],
        currentFolderId: 0,
        currentFolderName: 'All Files',
        selected: [],
        attached: @js($initialAttached),
        driveUrl: '',
        driveName: '',
        loading: false,
        fallbackClientId: @js($clientId),

        getJson() {
            return JSON.stringify(this.attached);
        },
        syncInput() {
            var el = document.getElementById(this.inputId);
            if (el) el.value = JSON.stringify(this.attached);
        },
        async resolveClientId() {
            var wireProp = @js($wireClientId);
            if (wireProp) {
                try {
                    var val = $wire.get(wireProp);
                    if (val !== undefined && val !== null && val !== '') return Number(val) || null;
                } catch(e) { /* fall through */ }
            }
            return this.fallbackClientId ? (Number(this.fallbackClientId) || null) : null;
        },
        async loadFolders() {
            try {
                var clientId = await this.resolveClientId();
                var res = await $wire.getPickableFolders(this.currentFolderId, clientId);
                if (res) {
                    this.folders = res.folders || [];
                    this.breadcrumbs = res.breadcrumbs || [];
                }
            } catch(e) { console.error('Error loading folders:', e); }
        },
        async loadFiles() {
            this.loading = true;
            try {
                var clientId = await this.resolveClientId();
                var isAll = (this.fileViewMode === 'all') || (this.search && this.search.trim().length > 0);
                var res = await $wire.getPickableFiles(this.search ? this.search.trim() : null, clientId, this.currentFolderId, isAll);
                this.files = Array.isArray(res) ? res : [];
            } catch(e) { console.error('Error loading files:', e); }
            this.loading = false;
        },
        async enterFolder(folderId, folderName) {
            this.currentFolderId = folderId;
            if (folderName) this.currentFolderName = folderName;
            this.fileViewMode = 'folders';
            this.search = '';
            await Promise.all([this.loadFolders(), this.loadFiles()]);
        },
        async goToParent() {
            if (this.breadcrumbs.length > 1) {
                var parent = this.breadcrumbs[this.breadcrumbs.length - 2];
                await this.enterFolder(parent.id, parent.name);
            } else {
                await this.goToRoot();
            }
        },
        async goToRoot() {
            this.currentFolderId = 0;
            this.currentFolderName = 'All Files';
            this.search = '';
            await Promise.all([this.loadFolders(), this.loadFiles()]);
        },
        async setViewMode(mode) {
            this.fileViewMode = mode;
            if (mode === 'all') {
                await this.loadFiles();
            } else {
                await Promise.all([this.loadFolders(), this.loadFiles()]);
            }
        },
        async handleSearchInput() {
            await this.loadFiles();
        },
        clearSearch() {
            this.search = '';
            this.loadFiles();
        },
        switchTab(tab) {
            this.activeTab = tab;
            if (tab === 'files') {
                this.loadFolders();
                this.loadFiles();
            }
        },
        toggleFile(file) {
            var idx = this.selected.findIndex(function(f) { return f.id === file.id && f.type !== 'drive'; });
            if (idx >= 0) {
                this.selected.splice(idx, 1);
            } else {
                if (!this.multiple) {
                    this.selected = [{ id: file.id, name: file.name, url: file.url, type: file.type }];
                } else {
                    this.selected.push({ id: file.id, name: file.name, url: file.url, type: file.type });
                }
            }
        },
        toggleDriveFile(file) {
            var idx = this.selected.findIndex(function(f) { return f.id === file.id && f.type === 'drive'; });
            if (idx >= 0) {
                this.selected.splice(idx, 1);
            } else {
                if (!this.multiple) {
                    this.selected = [{ id: file.id, name: file.name, url: file.url, type: 'drive' }];
                } else {
                    this.selected.push({ id: file.id, name: file.name, url: file.url, type: 'drive' });
                }
            }
        },
        isSelected(file) {
            return this.selected.some(function(f) { return f.id === file.id && f.type !== 'drive'; });
        },
        isDriveSelected(file) {
            return this.selected.some(function(f) { return f.id === file.id && f.type === 'drive'; });
        },
        addDriveLink() {
            if (!this.driveUrl) return;
            var item = {
                id: null,
                name: this.driveName || 'Drive Link',
                url: this.driveUrl,
                type: 'drive'
            };
            if (!this.multiple) {
                this.selected = [item];
            } else {
                this.selected.push(item);
            }
            this.driveUrl = '';
            this.driveName = '';
        },
        addDriveFile(e) {
            var file = e.detail;
            if (!file || !file.url) return;
            var item = {
                id: file.id || null,
                name: file.name || 'Drive File',
                url: file.url,
                type: 'drive'
            };
            if (!this.multiple) {
                this.selected = [item];
            } else {
                this.selected.push(item);
            }
        },
        removeSelected(idx) {
            this.selected.splice(idx, 1);
        },
        async removeAttached(idx) {
            var current = this.attached.slice();
            current.splice(idx, 1);
            this.attached = current;
            this.syncInput();
            await $wire.set(this.wireProp, this.getJson());
        },
        async confirm() {
            this.attached = JSON.parse(JSON.stringify(this.selected));
            this.syncInput();
            await $wire.set(this.wireProp, this.getJson());
            this.open = false;
        },
        openPicker() {
            this.selected = JSON.parse(JSON.stringify(this.attached));
            this.search = '';
            this.driveUrl = '';
            this.driveName = '';
            this.currentFolderId = 0;
            this.currentFolderName = 'All Files';
            this.fileViewMode = 'folders';
            this.activeTab = 'files';
            this.open = true;
            this.loadFolders();
            this.loadFiles();
        },
    }"
    x-init="$nextTick(() => syncInput())"
    x-on:drive-file-selected.window="addDriveFile($event)"
>
    <input type="hidden" id="{{ $inputId }}" value="[]" />

    <div>
        <button type="button" @click="openPicker()" class="btn btn-secondary btn-sm">
            <i class="fas fa-paperclip text-xs"></i>
            <span>Attach Files</span>
            <template x-if="attached.length > 0">
                <span
                    class="ml-1 inline-flex items-center justify-center w-5 h-5 rounded-full bg-[var(--brand)] text-white text-[10px] font-bold"
                    x-text="attached.length"
                ></span>
            </template>
        </button>

        <template x-if="attached.length > 0">
            <div class="mt-2 flex flex-wrap gap-1.5">
                <template x-for="(item, idx) in attached" :key="idx">
                    <div
                        class="group inline-flex items-center gap-1.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 rounded-lg pl-2 pr-1 py-1 transition-colors"
                    >
                        <template x-if="item.type === 'image' && item.url">
                            <img :src="item.url" class="w-6 h-6 rounded object-cover border border-gray-200 shrink-0" />
                        </template>
                        <template x-if="!(item.type === 'image' && item.url)">
                            <i
                                class="fas text-xs shrink-0"
                                :class="item.type === 'drive'
                                    ? 'fa-google-drive text-blue-500'
                                    : item.type === 'video'
                                      ? 'fa-video text-purple-500'
                                      : 'fa-file text-gray-400'"
                            ></i>
                        </template>
                        <span class="text-xs text-gray-700 max-w-[140px] truncate font-medium" x-text="item.name || 'File'"></span>
                        <button
                            type="button"
                            @click.stop="removeAttached(idx)"
                            class="w-5 h-5 flex items-center justify-center rounded hover:bg-red-100 text-gray-400 hover:text-red-500 transition-colors"
                            title="Remove"
                        >
                            <i class="fas fa-times text-[9px]"></i>
                        </button>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- Main Modal --}}
    <template x-if="open">
        <div
            class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4"
            @click.self="open = false"
            x-on:keydown.escape.window="open = false"
        >
            <div
                class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-2xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl animate-fade-in"
            >
                {{-- Header --}}
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-[rgba(var(--brand-rgb),0.1)] flex items-center justify-center text-[var(--brand)]">
                            <i class="fas fa-paperclip text-sm"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-900 leading-tight">Attach Files</h3>
                            <p class="text-xs text-gray-500">Pick from system files or Google Drive</p>
                        </div>
                    </div>
                    <button
                        @click="open = false"
                        class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                {{-- Tabs --}}
                <div class="px-5 pt-3 pb-2 shrink-0 bg-gray-50/50 border-b border-gray-100">
                    <div class="flex gap-1 p-1 bg-gray-200/70 rounded-xl">
                        <button
                            type="button"
                            @click="switchTab('files')"
                            class="flex-1 flex items-center justify-center gap-2 px-3 py-2 text-xs sm:text-sm font-semibold rounded-lg transition-all"
                            :class="activeTab === 'files'
                                ? 'bg-white text-gray-900 shadow-sm'
                                : 'text-gray-500 hover:text-gray-700'"
                        >
                            <i class="fas fa-folder text-amber-500 text-xs"></i>
                            <span>System Files & Folders</span>
                        </button>
                        <button
                            type="button"
                            @click="switchTab('drive')"
                            class="flex-1 flex items-center justify-center gap-2 px-3 py-2 text-xs sm:text-sm font-semibold rounded-lg transition-all"
                            :class="activeTab === 'drive'
                                ? 'bg-white text-gray-900 shadow-sm'
                                : 'text-gray-500 hover:text-gray-700'"
                        >
                            <i class="fab fa-google-drive text-blue-500 text-xs"></i>
                            <span>Google Drive</span>
                        </button>
                    </div>
                </div>

                {{-- Tab Content --}}
                <div class="flex-1 overflow-y-auto min-h-0 flex flex-col">
                    {{-- ===== FILES TAB ===== --}}
                    <div x-show="activeTab === 'files'" class="flex flex-col h-full">
                        {{-- Controls: View Switcher & Search Bar --}}
                        <div class="px-5 py-3 border-b border-gray-100 shrink-0 space-y-2.5 bg-white">
                            <div class="flex items-center gap-2">
                                {{-- Search Input --}}
                                <div class="relative flex-1">
                                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                    <input
                                        type="text"
                                        x-model.debounce.300ms="search"
                                        @input.debounce.300ms="handleSearchInput()"
                                        placeholder="Search all files & folders..."
                                        class="w-full pl-8 pr-8 py-2 text-xs sm:text-sm border border-gray-200 rounded-xl focus:border-[var(--brand)] focus:ring-1 focus:ring-[var(--brand)] bg-gray-50 focus:bg-white transition-all outline-none"
                                    />
                                    <button
                                        type="button"
                                        x-show="search.length > 0"
                                        @click="clearSearch()"
                                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-xs"
                                    >
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                </div>

                                {{-- Mode Toggle: Folders vs All Files --}}
                                <div class="flex items-center p-1 bg-gray-100 rounded-xl shrink-0 text-xs">
                                    <button
                                        type="button"
                                        @click="setViewMode('folders')"
                                        class="flex items-center gap-1.5 px-2.5 py-1.5 font-semibold rounded-lg transition-all"
                                        :class="fileViewMode === 'folders' && !search
                                            ? 'bg-white text-gray-900 shadow-sm'
                                            : 'text-gray-500 hover:text-gray-700'"
                                        title="Browse by folders"
                                    >
                                        <i class="fas fa-folder text-amber-500 text-xs"></i>
                                        <span class="hidden sm:inline">Folders</span>
                                    </button>
                                    <button
                                        type="button"
                                        @click="setViewMode('all')"
                                        class="flex items-center gap-1.5 px-2.5 py-1.5 font-semibold rounded-lg transition-all"
                                        :class="fileViewMode === 'all' || search
                                            ? 'bg-white text-gray-900 shadow-sm'
                                            : 'text-gray-500 hover:text-gray-700'"
                                        title="Show all files flat list"
                                    >
                                        <i class="fas fa-list text-blue-500 text-xs"></i>
                                        <span class="hidden sm:inline">All Files</span>
                                    </button>
                                </div>
                            </div>

                            {{-- Navigation Breadcrumbs & Back Button (when in folder mode and not searching) --}}
                            <div x-show="fileViewMode === 'folders' && !search" class="flex items-center justify-between gap-2 pt-1">
                                <div class="flex items-center gap-1.5 overflow-x-auto text-xs py-0.5 max-w-full">
                                    {{-- Back Button --}}
                                    <button
                                        type="button"
                                        x-show="currentFolderId !== 0"
                                        @click="goToParent()"
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium transition-colors shrink-0"
                                        title="Go back up one level"
                                    >
                                        <i class="fas fa-arrow-left text-[10px]"></i>
                                        <span>Back</span>
                                    </button>

                                    {{-- Root / All Files crumb --}}
                                    <button
                                        type="button"
                                        @click="goToRoot()"
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-lg transition-colors shrink-0"
                                        :class="currentFolderId === 0
                                            ? 'font-bold text-[var(--brand)] bg-[rgba(var(--brand-rgb),0.08)]'
                                            : 'text-gray-500 hover:bg-gray-100'"
                                    >
                                        <i class="fas fa-home text-[10px]"></i>
                                        <span>Root</span>
                                    </button>

                                    {{-- Breadcrumb trail --}}
                                    <template x-for="(crumb, i) in breadcrumbs" :key="crumb.id">
                                        <div class="flex items-center gap-1 shrink-0">
                                            <i class="fas fa-chevron-right text-[8px] text-gray-300"></i>
                                            <button
                                                type="button"
                                                @click="enterFolder(crumb.id, crumb.name)"
                                                class="px-2 py-1 rounded-lg transition-colors truncate max-w-[120px]"
                                                :class="crumb.id === currentFolderId
                                                    ? 'font-bold text-[var(--brand)] bg-[rgba(var(--brand-rgb),0.08)]'
                                                    : 'text-gray-500 hover:bg-gray-100'"
                                                x-text="crumb.name"
                                            ></button>
                                        </div>
                                    </template>
                                </div>

                                {{-- Folder Items Count --}}
                                <div class="text-[11px] text-gray-400 shrink-0 font-medium hidden sm:block">
                                    <span x-text="folders.length + ' folder' + (folders.length !== 1 ? 's' : '') + ', ' + files.length + ' file' + (files.length !== 1 ? 's' : '')"></span>
                                </div>
                            </div>

                            {{-- Search Info Banner --}}
                            <div x-show="search.length > 0" class="flex items-center justify-between text-xs text-gray-500 bg-blue-50/70 border border-blue-100 rounded-lg px-3 py-1.5">
                                <span>Searching across all files and folders for: "<strong class="text-blue-700" x-text="search"></strong>"</span>
                                <button type="button" @click="clearSearch()" class="text-blue-600 hover:underline text-[11px] font-medium">Clear search</button>
                            </div>
                        </div>

                        {{-- File & Folder Items Container --}}
                        <div class="flex-1 overflow-y-auto p-4 space-y-2 min-h-0">
                            {{-- Loading Spinner --}}
                            <template x-if="loading">
                                <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                                    <i class="fas fa-spinner fa-spin text-2xl mb-2 text-[var(--brand)]"></i>
                                    <p class="text-xs font-medium">Loading items...</p>
                                </div>
                            </template>

                            {{-- Empty State --}}
                            <template x-if="!loading && folders.length === 0 && files.length === 0">
                                <div class="flex flex-col items-center justify-center py-16 text-gray-400">
                                    <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mb-3">
                                        <i class="fas fa-folder-open text-3xl text-gray-300"></i>
                                    </div>
                                    <p class="text-sm font-semibold text-gray-700 mb-1">No items found</p>
                                    <p class="text-xs text-gray-400 max-w-xs text-center" x-text="search ? 'No files or folders match your search query.' : (currentFolderId > 0 ? 'This folder is empty.' : 'No files or folders available.')"></p>
                                    <button
                                        type="button"
                                        x-show="currentFolderId !== 0"
                                        @click="goToRoot()"
                                        class="mt-3 text-xs text-[var(--brand)] font-semibold hover:underline"
                                    >
                                        Return to Root
                                    </button>
                                </div>
                            </template>

                            {{-- ===== FOLDERS SECTION (When in folder mode and not searching, or matching folders) ===== --}}
                            <div x-show="!loading && fileViewMode === 'folders' && !search && folders.length > 0" class="space-y-1.5 mb-3">
                                <div class="flex items-center justify-between px-1 pb-1">
                                    <h4 class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">
                                        Folders (<span x-text="folders.length"></span>)
                                    </h4>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <template x-for="folder in folders" :key="'folder-' + folder.id">
                                        <button
                                            type="button"
                                            @click="enterFolder(folder.id, folder.name)"
                                            class="flex items-center gap-3 p-3 rounded-xl border border-gray-100 bg-white hover:bg-amber-50/50 hover:border-amber-200 transition-all text-left shadow-sm group"
                                        >
                                            <div class="w-10 h-10 rounded-xl bg-amber-50 border border-amber-100 flex items-center justify-center flex-shrink-0 group-hover:bg-amber-100 transition-colors">
                                                <i class="fas fa-folder text-amber-500 text-lg group-hover:scale-110 transition-transform"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-semibold text-gray-800 truncate group-hover:text-amber-700 transition-colors" x-text="folder.name"></p>
                                                <p class="text-[11px] text-gray-400">
                                                    <span x-text="folder.file_count + ' file' + (folder.file_count !== 1 ? 's' : '')"></span>
                                                    <template x-if="folder.subfolder_count > 0">
                                                        <span> &bull; <span x-text="folder.subfolder_count + ' folder' + (folder.subfolder_count !== 1 ? 's' : '')"></span></span>
                                                    </template>
                                                </p>
                                            </div>
                                            <i class="fas fa-chevron-right text-xs text-gray-300 group-hover:text-amber-500 group-hover:translate-x-0.5 transition-all"></i>
                                        </button>
                                    </template>
                                </div>
                            </div>

                            {{-- Divider if both folders and files exist --}}
                            <div x-show="!loading && fileViewMode === 'folders' && !search && folders.length > 0 && files.length > 0" class="py-1">
                                <div class="h-px bg-gray-100"></div>
                            </div>

                            {{-- ===== FILES SECTION ===== --}}
                            <div x-show="!loading && files.length > 0" class="space-y-1">
                                <div class="flex items-center justify-between px-1 pb-1">
                                    <h4 class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">
                                        <span x-text="fileViewMode === 'all' || search ? 'All Files' : (currentFolderId > 0 ? 'Files in ' + currentFolderName : 'Root Files')"></span>
                                        (<span x-text="files.length"></span>)
                                    </h4>
                                </div>

                                <template x-for="file in files" :key="'file-' + file.id">
                                    <label
                                        class="flex items-center gap-3 p-2.5 rounded-xl border border-transparent hover:border-gray-200 hover:bg-gray-50/80 cursor-pointer transition-all"
                                        :class="{ 'bg-blue-50/80 border-blue-200 ring-1 ring-blue-300': isSelected(file) }"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="isSelected(file)"
                                            @change="toggleFile(file)"
                                            class="rounded-md border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)] shrink-0 h-4 w-4"
                                        />

                                        {{-- File Preview Thumbnail / Icon --}}
                                        <div class="w-10 h-10 rounded-xl overflow-hidden flex items-center justify-center flex-shrink-0 bg-gray-100 border border-gray-200">
                                            <template x-if="file.type === 'image' && file.url">
                                                <img :src="file.url" class="w-full h-full object-cover" loading="lazy" />
                                            </template>
                                            <template x-if="!(file.type === 'image' && file.url)">
                                                <div
                                                    class="w-full h-full flex items-center justify-center"
                                                    :class="file.type === 'video' ? 'bg-purple-50 text-purple-500' : (file.type === 'image' ? 'bg-blue-50 text-blue-500' : 'bg-gray-50 text-gray-500')"
                                                >
                                                    <i
                                                        class="fas text-sm"
                                                        :class="file.type === 'video' ? 'fa-video' : (file.type === 'image' ? 'fa-image' : 'fa-file-alt')"
                                                    ></i>
                                                </div>
                                            </template>
                                        </div>

                                        {{-- File Info --}}
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-semibold text-gray-800 truncate" x-text="file.name"></p>
                                                {{-- Folder Badge (shown if file is inside a folder, or when viewing all / searching) --}}
                                                <template x-if="file.folder_name && file.folder_name !== 'Root'">
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200 shrink-0">
                                                        <i class="fas fa-folder text-[8px]"></i>
                                                        <span x-text="file.folder_name"></span>
                                                    </span>
                                                </template>
                                            </div>
                                            <p class="text-[11px] text-gray-400" x-text="file.size_label || ''"></p>
                                        </div>

                                        {{-- Selected Indicator --}}
                                        <template x-if="isSelected(file)">
                                            <div class="w-6 h-6 rounded-full bg-[var(--brand)] text-white flex items-center justify-center text-xs shrink-0 shadow-sm">
                                                <i class="fas fa-check text-[10px]"></i>
                                            </div>
                                        </template>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- ===== DRIVE TAB ===== --}}
                    <div x-show="activeTab === 'drive'" class="h-full">
                        @livewire ('partials.drive-browser')
                    </div>
                </div>

                {{-- Selected Items Bar --}}
                <template x-if="selected.length > 0">
                    <div class="px-5 py-2.5 border-t border-gray-100 bg-gray-50 shrink-0">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-xs font-bold text-gray-600 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-[var(--brand)]"></span>
                                <span><span x-text="selected.length"></span> selected</span>
                            </p>
                            <button
                                type="button"
                                @click="selected = []"
                                class="text-xs text-gray-400 hover:text-red-500 font-medium transition-colors"
                            >
                                Clear All
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto pr-1">
                            <template x-for="(item, idx) in selected" :key="'sel-' + idx">
                                <span
                                    class="inline-flex items-center gap-1.5 bg-white border border-gray-200 rounded-lg pl-2 pr-1.5 py-1 text-xs text-gray-700 shadow-sm"
                                >
                                    <template x-if="item.type === 'image' && item.url">
                                        <img :src="item.url" class="w-4 h-4 rounded object-cover shrink-0" />
                                    </template>
                                    <template x-if="!(item.type === 'image' && item.url)">
                                        <i
                                            class="fas text-[10px]"
                                            :class="item.type === 'drive' ? 'fa-google-drive text-blue-500' : 'fa-file text-gray-400'"
                                        ></i>
                                    </template>
                                    <span class="truncate max-w-[120px] font-medium" x-text="item.name"></span>
                                    <button
                                        type="button"
                                        @click="removeSelected(idx)"
                                        class="w-4 h-4 rounded hover:bg-red-50 text-gray-400 hover:text-red-500 flex items-center justify-center transition-colors"
                                    >
                                        <i class="fas fa-times text-[8px]"></i>
                                    </button>
                                </span>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Footer Action Buttons --}}
                <div class="flex items-center justify-between px-5 py-3.5 border-t border-gray-100 shrink-0 bg-white">
                    <button
                        type="button"
                        @click="open = false"
                        class="px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 rounded-xl transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="confirm()"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-[var(--brand)] text-white text-sm font-bold rounded-xl shadow-sm hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="selected.length === 0"
                    >
                        <i class="fas fa-check text-xs"></i>
                        <span>Attach</span>
                        <span
                            class="inline-flex items-center justify-center min-w-[20px] h-5 px-1 rounded-full bg-white/25 text-[11px] font-bold"
                            x-text="selected.length"
                        ></span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
