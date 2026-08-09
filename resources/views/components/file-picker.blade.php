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
        open: false,
        activeTab: 'files',
        search: '',
        files: [],
        folders: [],
        breadcrumbs: [],
        currentFolderId: 0,
        selected: [],
        attached: @js($initialAttached),
        driveUrl: '',
        driveName: '',
        loading: false,
        getJson() {
            return JSON.stringify(this.attached);
        },
        syncInput() {
            var el = document.getElementById(this.inputId);
            if (el) el.value = JSON.stringify(this.attached);
        },
        async loadFolders() {
            try {
                var clientId = @js($wireClientId) ? $wire.get(@js($wireClientId)) : {{ json_encode($clientId) }};
                var res = await $wire.getPickableFolders(this.currentFolderId, clientId);
                this.folders = res.folders || [];
                this.breadcrumbs = res.breadcrumbs || [];
            } catch(e) { console.error(e); }
        },
        async loadFiles() {
            this.loading = true;
            try {
                var clientId = @js($wireClientId) ? $wire.get(@js($wireClientId)) : {{ json_encode($clientId) }};
                var res = await $wire.getPickableFiles(this.search, clientId, this.currentFolderId);
                this.files = res;
            } catch(e) { console.error(e); }
            this.loading = false;
        },
        async enterFolder(folderId) {
            this.currentFolderId = folderId;
            this.search = '';
            await this.loadFolders();
            await this.loadFiles();
        },
        async goToRoot() {
            this.currentFolderId = 0;
            this.search = '';
            await this.loadFolders();
            await this.loadFiles();
        },
        switchTab(tab) {
            this.activeTab = tab;
            if (tab === 'files') {
                this.loadFolders();
                this.loadFiles();
            }
        },
        toggleFile(file) {
            var idx = this.selected.findIndex(function(f) { return f.id === file.id; });
            if (idx >= 0) {
                this.selected.splice(idx, 1);
            } else {
                this.selected.push({ id: file.id, name: file.name, url: file.url, type: file.type });
            }
        },
        toggleDriveFile(file) {
            var idx = this.selected.findIndex(function(f) { return f.id === file.id && f.type === 'drive'; });
            if (idx >= 0) {
                this.selected.splice(idx, 1);
            } else {
                this.selected.push({ id: file.id, name: file.name, url: file.url, type: 'drive' });
            }
        },
        isSelected(file) {
            return this.selected.some(function(f) { return f.id === file.id; });
        },
        isDriveSelected(file) {
            return this.selected.some(function(f) { return f.id === file.id && f.type === 'drive'; });
        },
        addDriveLink() {
            if (!this.driveUrl) return;
            this.selected.push({
                id: null,
                name: this.driveName || 'Drive Link',
                url: this.driveUrl,
                type: 'drive'
            });
            this.driveUrl = '';
            this.driveName = '';
        },
        addDriveFile(e) {
            var file = e.detail;
            if (!file || !file.url) return;
            this.selected.push({
                id: file.id || null,
                name: file.name || 'Drive File',
                url: file.url,
                type: 'drive'
            });
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
                <span class="ml-1 inline-flex items-center justify-center w-5 h-5 rounded-full bg-[var(--brand)] text-white text-[10px] font-bold" x-text="attached.length"></span>
            </template>
        </button>

        <template x-if="attached.length > 0">
            <div class="mt-2 flex flex-wrap gap-1.5">
                <template x-for="(item, idx) in attached" :key="idx">
                    <div class="group inline-flex items-center gap-1.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 rounded-lg pl-2 pr-1 py-1 transition-colors">
                        <template x-if="item.type === 'image' && item.url && item.url.startsWith('/')">
                            <img :src="item.url" class="w-6 h-6 rounded object-cover border border-gray-200 shrink-0" />
                        </template>
                        <template x-if="!(item.type === 'image' && item.url && item.url.startsWith('/'))">
                            <i class="fas text-xs shrink-0" :class="item.type === 'drive' ? 'fa-google-drive text-blue-500' : item.type === 'image' ? 'fa-image text-blue-500' : item.type === 'video' ? 'fa-video text-purple-500' : 'fa-file text-gray-400'"></i>
                        </template>
                        <span class="text-xs text-gray-700 max-w-[140px] truncate" x-text="item.name || 'File'"></span>
                        <button type="button" @click.stop="removeAttached(idx)" class="w-5 h-5 flex items-center justify-center rounded hover:bg-red-100 text-gray-400 hover:text-red-500 transition-colors" title="Remove">
                            <i class="fas fa-times text-[9px]"></i>
                        </button>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- Main Modal --}}
    <template x-if="open">
        <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4" @click.self="open = false" x-on:keydown.escape.window="open = false">
            <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-2xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">

                {{-- Header --}}
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0">
                    <div>
                        <h3 class="font-bold text-gray-900">Attach Files</h3>
                    </div>
                    <button @click="open = false" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-gray-100 text-gray-400 transition-colors">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                {{-- Tabs --}}
                <div class="px-5 pt-3 shrink-0">
                    <div class="flex gap-1 p-1 bg-gray-100 rounded-xl">
                        <button type="button" @click="switchTab('files')" class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 text-sm font-semibold rounded-lg transition-all" :class="activeTab === 'files' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            <i class="fas fa-folder text-xs"></i>
                            Files
                        </button>
                        <button type="button" @click="switchTab('drive')" class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 text-sm font-semibold rounded-lg transition-all" :class="activeTab === 'drive' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            <i class="fab fa-google-drive text-xs"></i>
                            Drive
                        </button>
                        <button type="button" @click="switchTab('upload')" class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 text-sm font-semibold rounded-lg transition-all" :class="activeTab === 'upload' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            <i class="fas fa-upload text-xs"></i>
                            Upload
                        </button>
                    </div>
                </div>

                {{-- Tab Content --}}
                <div class="flex-1 overflow-y-auto min-h-0">

                    {{-- ===== FILES TAB (First) ===== --}}
                    <div x-show="activeTab === 'files'" class="flex flex-col h-full">
                        {{-- Breadcrumbs --}}
                        <div class="px-5 py-2 border-b border-gray-100 shrink-0" x-show="breadcrumbs.length > 0">
                            <nav class="flex items-center gap-1 text-xs overflow-x-auto">
                                <button @click="goToRoot()" class="flex-shrink-0 px-2 py-1 rounded-lg hover:bg-gray-100 transition-colors" :class="currentFolderId === 0 ? 'font-bold text-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : 'text-gray-500'">
                                    <i class="fas fa-home text-[10px] mr-1"></i> All
                                </button>
                                <template x-for="(crumb, i) in breadcrumbs" :key="crumb.id">
                                    <div class="flex items-center gap-1 flex-shrink-0">
                                        <i class="fas fa-chevron-right text-[8px] text-gray-300"></i>
                                        <button @click="enterFolder(crumb.id)" class="px-2 py-1 rounded-lg hover:bg-gray-100 transition-colors truncate max-w-[100px]" :class="i === breadcrumbs.length - 1 ? 'font-bold text-[var(--brand)]' : 'text-gray-500'" x-text="crumb.name"></button>
                                    </div>
                                </template>
                            </nav>
                        </div>

                        {{-- Search --}}
                        <div class="px-5 py-3 border-b border-gray-100 shrink-0">
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="text" x-model.debounce.300ms="search" @input.debounce.300ms="loadFiles()" placeholder="Search files..." class="w-full pl-9 pr-4 py-2.5 text-sm border-gray-200 rounded-xl focus:border-[var(--brand)] focus:ring-[var(--brand)] bg-gray-50 focus:bg-white" />
                            </div>
                        </div>

                        {{-- File List --}}
                        <div class="flex-1 overflow-y-auto p-4 space-y-1 min-h-0">
                            <template x-if="loading">
                                <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                                    <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                                    <p class="text-sm">Loading...</p>
                                </div>
                            </template>
                            <template x-if="!loading && folders.length === 0 && files.length === 0">
                                <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                                    <i class="fas fa-folder-open text-3xl text-gray-200 mb-2"></i>
                                    <p class="text-sm">No files found</p>
                                </div>
                            </template>

                            <template x-for="folder in folders" :key="'f-' + folder.id">
                                <button type="button" @click="enterFolder(folder.id)" class="w-full flex items-center gap-3 p-3 rounded-xl hover:bg-amber-50 transition-all text-left group">
                                    <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-folder text-amber-400"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-800 truncate" x-text="folder.name"></p>
                                        <p class="text-[11px] text-gray-400" x-text="folder.file_count + ' file' + (folder.file_count !== 1 ? 's' : '')"></p>
                                    </div>
                                    <i class="fas fa-chevron-right text-xs text-gray-300 group-hover:text-amber-400"></i>
                                </button>
                            </template>

                            <template x-if="folders.length > 0 && files.length > 0">
                                <div class="py-2"><div class="h-px bg-gray-100"></div></div>
                            </template>

                            <template x-for="file in files" :key="file.id">
                                <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50 cursor-pointer transition-all" :class="isSelected(file) && 'bg-blue-50 ring-1 ring-blue-200'">
                                    <input type="checkbox" :checked="isSelected(file)" @change="toggleFile(file)" class="rounded-lg border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)] shrink-0" />
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" :class="file.type === 'image' ? 'bg-blue-50' : file.type === 'video' ? 'bg-purple-50' : 'bg-gray-50'">
                                        <i class="fas text-sm" :class="file.type === 'image' ? 'fa-image text-blue-500' : file.type === 'video' ? 'fa-video text-purple-500' : 'fa-file text-gray-500'"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-800 truncate" x-text="file.name"></p>
                                        <p class="text-[11px] text-gray-400" x-text="file.size_label"></p>
                                    </div>
                                    <template x-if="isSelected(file)">
                                        <i class="fas fa-check-circle text-[var(--brand)]"></i>
                                    </template>
                                </label>
                            </template>
                        </div>
                    </div>

                    {{-- ===== DRIVE TAB (Middle) ===== --}}
                    <div x-show="activeTab === 'drive'" class="h-full">
                        @livewire ('partials.drive-browser')
                    </div>

                    {{-- ===== UPLOAD TAB (Last - auto-attach) ===== --}}
                    <div x-show="activeTab === 'upload'" class="p-5">
                        <div x-data="{
                            uploadProgress: 0,
                            uploading: false,
                            uploadedFile: null,
                            autoAttach: false,
                        }"
                        x-on:upload:started.window="if ($event.detail.id === 'newFileUpload') { uploading = true; uploadProgress = 0; uploadedFile = null; autoAttach = false; }"
                        x-on:upload:progress.window="if ($event.detail.id === 'newFileUpload') { uploadProgress = Math.round($event.detail.progress); uploading = true; }"
                        x-on:upload:finished.window="if ($event.detail.id === 'newFileUpload') {
                            uploadProgress = 100;
                            uploading = false;
                            autoAttach = true;
                            var ev = $event.detail;
                            if (ev && ev.files && ev.files.length) {
                                ev.files.forEach(function(f) {
                                    selected.push({ id: f.id || null, name: f.name || 'Uploaded File', url: f.url || f.preview_url || '', type: f.type || 'file' });
                                });
                            } else if (ev && ev.file) {
                                var f = ev.file;
                                selected.push({ id: f.id || null, name: f.name || 'Uploaded File', url: f.url || f.preview_url || '', type: f.type || 'file' });
                            } else {
                                loadFolders();
                                loadFiles();
                            }
                            setTimeout(function() {
                                uploadProgress = 0;
                                uploadedFile = 'File attached!';
                                setTimeout(function() { uploadedFile = null; }, 2000);
                            }, 300);
                        }"
                        x-on:upload:cancelled.window="if ($event.detail.id === 'newFileUpload') { uploading = false; uploadProgress = 0; uploadedFile = null; }"
                        >
                            <input type="file" wire:model="newFileUpload" x-ref="localFileInput" class="hidden" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv" />

                            <div x-show="!uploading && !uploadedFile">
                                <button type="button" @click="$refs.localFileInput.click()" class="w-full flex flex-col items-center justify-center gap-3 py-14 border-2 border-dashed border-gray-200 rounded-2xl hover:border-[var(--brand)] hover:bg-[rgba(var(--brand-rgb),0.02)] transition-all group cursor-pointer">
                                    <div class="w-14 h-14 rounded-2xl bg-gray-100 group-hover:bg-[rgba(var(--brand-rgb),0.1)] flex items-center justify-center transition-colors">
                                        <i class="fas fa-cloud-upload-alt text-2xl text-gray-300 group-hover:text-[var(--brand)] transition-colors"></i>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm font-semibold text-gray-700">Click to upload</p>
                                        <p class="text-xs text-gray-400 mt-1">PDF, DOC, Images, Videos — will attach directly</p>
                                    </div>
                                </button>
                            </div>

                            <div x-show="uploading" x-transition class="py-10">
                                <div class="flex items-center gap-4 mb-4">
                                    <div class="w-12 h-12 rounded-2xl bg-blue-100 flex items-center justify-center">
                                        <i class="fas fa-spinner fa-spin text-xl text-blue-500"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm font-bold text-gray-800">Uploading & Attaching...</p>
                                        <p class="text-xs text-gray-500">File will be attached automatically</p>
                                    </div>
                                    <span class="text-lg font-bold text-blue-600" x-text="uploadProgress + '%'"></span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-[var(--brand)] transition-all duration-300" :style="'width:' + uploadProgress + '%'"></div>
                                </div>
                            </div>

                            <div x-show="uploadedFile && !uploading" x-transition class="py-6 text-center">
                                <div class="w-14 h-14 rounded-2xl bg-emerald-100 flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-check text-2xl text-emerald-600"></i>
                                </div>
                                <p class="text-sm font-bold text-emerald-700" x-text="uploadedFile"></p>
                                <button type="button" @click="uploadedFile = null; $refs.localFileInput.click()" class="mt-3 text-sm text-[var(--brand)] hover:underline font-medium">Upload another</button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Selected Items --}}
                <template x-if="selected.length > 0">
                    <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 shrink-0">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider"><span x-text="selected.length"></span> selected</p>
                            <button @click="selected = []" class="text-xs text-gray-400 hover:text-red-500 font-medium">Clear</button>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="(item, idx) in selected" :key="idx">
                                <span class="inline-flex items-center gap-1.5 bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-700">
                                    <i class="fas text-[11px]" :class="item.type === 'drive' ? 'fa-google-drive text-blue-500' : 'fa-file text-gray-400'"></i>
                                    <span class="truncate max-w-[100px] font-medium" x-text="item.name"></span>
                                    <button type="button" @click="removeSelected(idx)" class="text-gray-400 hover:text-red-500"><i class="fas fa-times text-[9px]"></i></button>
                                </span>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Footer --}}
                <div class="flex items-center justify-between px-5 py-4 border-t border-gray-100 shrink-0">
                    <button @click="open = false" class="px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-100 rounded-xl transition-colors">Cancel</button>
                    <button @click="confirm()" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[var(--brand)] text-white text-sm font-bold rounded-xl shadow-sm hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed" :disabled="selected.length === 0">
                        <i class="fas fa-check text-xs"></i>
                        <span>Attach</span>
                        <span class="inline-flex items-center justify-center min-w-[22px] h-5 px-1 rounded-full bg-white/20 text-[11px] font-bold" x-text="selected.length"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
