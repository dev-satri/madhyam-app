@props ([
    'clientId' => null,
    'multiple' => true,
    'wire' => 'formAttachments',
    'wireJson' => null,
])

<div
    x-data="{
        target: @js($wire),
        jsonTarget: @js($wireJson),
        open: false,
        search: '',
        files: [],
        selected: [],
        attached: [],
        driveUrl: '',
        driveName: '',
        loading: false,
        async loadFiles() {
            this.loading = true;
            try {
                const res = await $wire.getPickableFiles(this.search, {{ json_encode($clientId) }});
                this.files = res;
            } catch(e) { console.error(e); }
            this.loading = false;
        },
        toggleFile(file) {
            const idx = this.selected.findIndex(f => f.id === file.id);
            if (idx >= 0) {
                this.selected.splice(idx, 1);
            } else {
                this.selected.push({ id: file.id, name: file.name, url: file.url, type: file.type });
            }
        },
        isSelected(file) {
            return this.selected.some(f => f.id === file.id);
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
        removeSelected(idx) {
            this.selected.splice(idx, 1);
        },
        syncToJson() {
            if (!this.jsonTarget) return;
            const el = document.querySelector(`[wire\\:model="
    ${this.jsonTarget}"]`);
    if
    (el)
    {
    const
    json="JSON.stringify(this.attached);"
    el.value="json;"
    el.dispatchEvent(new
    Event('input',
    { bubbles: true }
    ));
    }
    },
    removeAttached(idx)
    {
    this.attached.splice(idx,
    1);
    if
    (!window.__filePickerData)
    window.__filePickerData={};
    window.__filePickerData[this.target]="JSON.parse(JSON.stringify(this.attached));"
    this.syncToJson();
    },
    confirm()
    {
    this.attached="JSON.parse(JSON.stringify(this.selected));"
    if
    (!window.__filePickerData)
    window.__filePickerData={};
    window.__filePickerData[this.target]="this.attached;"
    this.syncToJson();
    this.open="false;"
    },
    openPicker()
    {
    this.selected="JSON.parse(JSON.stringify(this.attached));"
    this.search=""
    ;
    this.driveUrl=""
    ;
    this.driveName=""
    ;
    this.open="true;"
    this.loadFiles();
    }
    }"
>
    {{-- Trigger + inline preview --}}
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

        {{-- Inline preview of attached files --}}
        <template x-if="attached.length > 0">
            <div class="mt-2 flex flex-wrap gap-1.5">
                <template x-for="(item, idx) in attached" :key="idx">
                    <div
                        class="group inline-flex items-center gap-1.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 rounded-lg pl-2 pr-1 py-1 transition-colors"
                    >
                        {{-- Icon / thumbnail --}}
                        <template x-if="item.type === 'image' && item.url && item.url.startsWith('/')">
                            <img :src="item.url" class="w-6 h-6 rounded object-cover border border-gray-200 shrink-0" />
                        </template>
                        <template x-if="!(item.type === 'image' && item.url && item.url.startsWith('/'))">
                            <i
                                class="fas text-xs shrink-0"
                                :class="item.type === 'drive'
                                    ? 'fa-google-drive text-blue-500'
                                    : item.type === 'image'
                                      ? 'fa-image text-blue-500'
                                      : item.type === 'video'
                                        ? 'fa-video text-purple-500'
                                        : 'fa-file text-gray-400'"
                            ></i>
                        </template>

                        {{-- Name --}}
                        <span class="text-xs text-gray-700 max-w-[140px] truncate" x-text="item.name || 'File'"></span>

                        {{-- Remove --}}
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

    {{-- Modal --}}
    <template x-if="open">
        <div
            class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4"
            @click.self="open = false"
            x-on:keydown.escape.window="open = false"
        >
            <div
                class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg max-h-[85vh] flex flex-col overflow-hidden"
            >
                {{-- Header --}}
                <div class="flex items-center justify-between px-4 py-3 border-b shrink-0">
                    <h3 class="font-bold text-gray-900">Attach Files</h3>
                    <button
                        @click="open = false"
                        class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400"
                    >
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                {{-- Drive Link --}}
                <div class="px-4 py-3 border-b bg-gray-50 shrink-0">
                    <p class="text-[11px] font-semibold text-gray-500 uppercase mb-2">Paste a drive link</p>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input
                            type="url"
                            x-model="driveUrl"
                            placeholder="https://drive.google.com/file/d/..."
                            class="form-input text-sm flex-1"
                        />
                        <input
                            type="text"
                            x-model="driveName"
                            placeholder="Label (optional)"
                            class="form-input text-sm w-full sm:w-32"
                        />
                        <button
                            type="button"
                            @click="addDriveLink()"
                            class="btn btn-primary btn-sm px-3 shrink-0"
                            :disabled="!driveUrl"
                        >
                            <i class="fas fa-plus text-xs"></i> Add
                        </button>
                    </div>
                </div>

                {{-- Search --}}
                <div class="px-4 py-2 border-b shrink-0">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                        <input
                            type="search"
                            x-model.debounce.300ms="search"
                            @input.debounce.300ms="loadFiles()"
                            placeholder="Search files from Media library..."
                            class="form-input pl-9 py-2 text-sm"
                        />
                    </div>
                </div>

                {{-- File List --}}
                <div class="flex-1 overflow-y-auto p-4 space-y-1 min-h-0">
                    <template x-if="loading">
                        <div class="text-center py-8 text-gray-400 text-sm">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Loading...
                        </div>
                    </template>
                    <template x-if="!loading && files.length === 0">
                        <div class="text-center py-8 text-gray-400 text-sm">
                            <i class="fas fa-folder-open text-2xl text-gray-200 mb-2 block"></i>
                            No files found
                        </div>
                    </template>
                    <template x-for="file in files" :key="file.id">
                        <label
                            class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 cursor-pointer transition-colors"
                            :class="isSelected(file) && 'bg-blue-50 ring-1 ring-blue-200'"
                        >
                            <input
                                type="checkbox"
                                :checked="isSelected(file)"
                                @change="toggleFile(file)"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 shrink-0"
                            />
                            <div
                                class="flex-shrink-0 w-8 h-8 rounded flex items-center justify-center"
                                :class="file.type === 'image'
                                    ? 'bg-blue-50'
                                    : file.type === 'video'
                                      ? 'bg-purple-50'
                                      : 'bg-gray-50'"
                            >
                                <i
                                    class="fas text-xs"
                                    :class="file.type === 'image'
                                        ? 'fa-image text-blue-500'
                                        : file.type === 'video'
                                          ? 'fa-video text-purple-500'
                                          : 'fa-file text-gray-500'"
                                ></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate" x-text="file.name"></p>
                                <p class="text-[11px] text-gray-400" x-text="file.size_label"></p>
                            </div>
                        </label>
                    </template>
                </div>

                {{-- Selected preview inside modal --}}
                <template x-if="selected.length > 0">
                    <div class="px-4 py-2 border-t bg-blue-50/50 shrink-0">
                        <p class="text-[11px] font-semibold text-blue-600 uppercase mb-1"><span x-text="selected.length"></span> selected</p>
                        <div class="flex flex-wrap gap-1">
                            <template x-for="(item, idx) in selected" :key="idx">
                                <span
                                    class="inline-flex items-center gap-1 bg-white border border-blue-200 rounded-lg px-2 py-0.5 text-xs text-blue-700"
                                >
                                    <i
                                        class="fas"
                                        :class="item.type === 'drive'
                                            ? 'fa-google-drive text-blue-500'
                                            : 'fa-file text-gray-400'"
                                    ></i>
                                    <span class="truncate max-w-[120px]" x-text="item.name"></span>
                                    <button
                                        type="button"
                                        @click="removeSelected(idx)"
                                        class="text-blue-300 hover:text-red-500 ml-0.5"
                                    >
                                        <i class="fas fa-times text-[9px]"></i>
                                    </button>
                                </span>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Footer --}}
                <div class="flex justify-end gap-2 px-4 py-3 border-t shrink-0">
                    <button @click="open = false" class="btn btn-secondary btn-sm">Cancel</button>
                    <button @click="confirm()" class="btn btn-primary btn-sm" :disabled="selected.length === 0">
                        <i class="fas fa-check text-xs mr-1"></i> Attach (<span x-text="selected.length"></span>)
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
