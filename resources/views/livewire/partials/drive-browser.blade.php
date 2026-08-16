<div class="space-y-4" x-data="{ confirmDelete: null }">
    @if (!$this->isConnected)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div class="flex items-center gap-3">
                <i class="fas fa-exclamation-triangle text-amber-500"></i>
                <div>
                    <p class="text-sm font-semibold text-amber-800">Google Drive Not Connected</p>
                    <p class="text-xs text-amber-600 mt-1">Please connect Google Drive in Settings first.</p>
                </div>
            </div>
        </div>
    @else
        {{-- Toolbar --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <i
                    class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none z-10"
                ></i>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search files..."
                    class="form-input text-sm"
                    style="padding-left: 2.5rem"
                />
            </div>
            <div class="flex gap-2 flex-shrink-0">
                <button wire:click="$set('showNewFolderForm', true)" class="btn btn-secondary btn-sm whitespace-nowrap">
                    <i class="fas fa-folder-plus mr-1"></i> New Folder
                </button>
                <button wire:click="loadFiles" class="btn btn-secondary btn-sm" wire:loading.attr="disabled">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>

        {{-- New Folder Form --}}
        @if ($this->showNewFolderForm)
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-folder-plus text-blue-500 text-sm"></i>
                    <span class="text-sm font-semibold text-blue-800">Create New Folder</span>
                </div>
                <form wire:submit="createFolder" class="flex gap-2">
                    <input
                        type="text"
                        wire:model="newFolderName"
                        class="form-input text-sm flex-1"
                        placeholder="Folder name"
                        autofocus
                    />
                    <button type="submit" class="btn btn-primary btn-sm">Create</button>
                    <button
                        type="button"
                        wire:click="$set('showNewFolderForm', false); $set('newFolderName', '')"
                        class="btn btn-secondary btn-sm"
                    >
                        Cancel
                    </button>
                </form>
            </div>
        @endif

        {{-- Breadcrumb --}}
        @if (!empty($this->folderPath) || $this->search)
            <div class="flex items-center gap-1.5 text-sm text-gray-500 flex-wrap">
                @if (!$this->search)
                    <button
                        wire:click="navigateToFolder(-1)"
                        class="hover:text-[var(--brand)] transition-colors font-medium"
                    >
                        <i class="fab fa-google-drive mr-1"></i> My Drive
                    </button>
                    @foreach ($this->folderPath as $index => $folder)
                        <i class="fas fa-chevron-right text-[10px] text-gray-300"></i>
                        <button
                            wire:click="navigateToFolder({{ $index }})"
                            class="hover:text-[var(--brand)] transition-colors {{ $index === count($this->folderPath) - 1 ? 'font-semibold text-gray-800' : '' }}"
                        >
                            {{ $folder['name'] }}
                        </button>
                    @endforeach
                @else
                    <span class="text-gray-400">Search results for</span>
                    <span class="font-semibold text-gray-700">"{{ $this->search }}"</span>
                    <button
                        wire:click="$set('search', ''); loadFiles()"
                        class="text-[var(--brand)] hover:underline ml-2"
                    >
                        <i class="fas fa-times mr-1"></i> Clear
                    </button>
                @endif
            </div>
        @endif

        {{-- File List --}}
        @if ($this->isLoading)
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-spinner fa-spin text-2xl mb-2 block"></i>
                <p class="text-sm">Loading...</p>
            </div>
        @elseif (collect($this->files)->isEmpty())
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-folder-open text-3xl text-gray-200 mb-2 block"></i>
                <p class="text-sm">No files found</p>
            </div>
        @else
            @php
                $filteredFiles = collect($this->files);
                if (! $this->search && ! empty($this->files)) {
                    $folders = $filteredFiles->filter(fn ($f) => ($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder');
                    $fileItems = $filteredFiles->filter(fn ($f) => ($f['mimeType'] ?? '') !== 'application/vnd.google-apps.folder');
                    $filteredFiles = $folders->values()->merge($fileItems->values());
                }
            @endphp
            <div class="space-y-1">
                @foreach ($filteredFiles as $file)
                    @php
                        $isFolder = ($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder';
                        $isSelected = $this->selectedFileId === ($file['id'] ?? '');
                        $isRenaming = $this->renamingFileId === ($file['id'] ?? '');
                    @endphp
                    <div
                        class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-150 {{ $isSelected ? 'bg-blue-50 ring-1 ring-blue-200' : 'hover:bg-gray-50' }} {{ $isRenaming ? 'bg-blue-50 ring-1 ring-blue-200' : '' }}"
                    >
                        {{-- Icon --}}
                        @if ($isFolder)
                            <button
                                wire:click="openFolder('{{ $file['id'] }}', '{{ addslashes($file['name']) }}')"
                                class="flex-shrink-0 w-9 h-9 rounded-lg bg-amber-50 flex items-center justify-center hover:bg-amber-100 transition-colors"
                            >
                                <i class="fas fa-folder text-amber-500 text-sm"></i>
                            </button>
                        @else
                            <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-gray-50 flex items-center justify-center">
                                @php
                                    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
                                    $iconMap = [
                                        'jpg' => 'fa-image text-blue-500', 'jpeg' => 'fa-image text-blue-500', 'png' => 'fa-image text-blue-500', 'gif' => 'fa-image text-blue-500', 'webp' => 'fa-image text-blue-500',
                                        'mp4' => 'fa-video text-purple-500', 'mov' => 'fa-video text-purple-500', 'avi' => 'fa-video text-purple-500',
                                        'pdf' => 'fa-file-pdf text-red-500',
                                        'doc' => 'fa-file-word text-blue-600', 'docx' => 'fa-file-word text-blue-600',
                                        'xls' => 'fa-file-excel text-green-600', 'xlsx' => 'fa-file-excel text-green-600',
                                    ];
                                    $icon = $iconMap[$ext] ?? 'fa-file text-gray-400';
                                @endphp
                                <i class="fas {{ $icon }} text-sm"></i>
                            </div>
                        @endif

                        {{-- Name --}}
                        <div class="flex-1 min-w-0">
                            @if ($isRenaming)
                                <form wire:submit="saveRename" class="flex gap-2">
                                    <input
                                        type="text"
                                        wire:model="renamingName"
                                        class="form-input text-sm py-1 flex-1"
                                        autofocus
                                    />
                                    <button type="submit" class="btn btn-primary btn-sm py-1 px-2">
                                        <i class="fas fa-check text-xs"></i>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="cancelRename"
                                        class="btn btn-secondary btn-sm py-1 px-2"
                                    >
                                        <i class="fas fa-times text-xs"></i>
                                    </button>
                                </form>
                            @else
                                <button
                                    @if ($isFolder)
                                        wire:click="openFolder('{{ $file['id'] }}', '{{ addslashes($file['name']) }}')"
                                    @else
                                        wire:click="selectFile({{ json_encode($file) }})"
                                    @endif
                                    class="text-left w-full"
                                >
                                    <p class="text-sm font-medium text-gray-800 truncate hover:text-[var(--brand)] transition-colors">{{ $file['name'] ?? 'Unnamed' }}</p>
                                    @if (!$isFolder && isset($file['size']))
                                        <p class="text-[11px] text-gray-400">{{ number_format((int) $file['size'] / 1024, 1) }} KB</p>
                                    @endif
                                </button>
                            @endif
                        </div>

                        {{-- Actions --}}
                        @if (!$isRenaming)
                            <div class="flex items-center gap-1 flex-shrink-0">
                                @if (!$isFolder)
                                    <a
                                        href="{{ $file['webViewLink'] ?? '#' }}"
                                        target="_blank"
                                        class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-[var(--brand)] transition-colors"
                                        title="Open in Drive"
                                        onclick="event.stopPropagation()"
                                    >
                                        <i class="fas fa-external-link-alt text-[10px]"></i>
                                    </a>
                                @endif
                                <button
                                    wire:click="startRename({{ json_encode($file) }})"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-[var(--brand)] transition-colors"
                                    title="Rename"
                                >
                                    <i class="fas fa-pen text-[10px]"></i>
                                </button>
                                @if (!$isFolder)
                                    <button
                                        wire:click="startMove({{ json_encode($file) }})"
                                        class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-[var(--brand)] transition-colors"
                                        title="Move"
                                    >
                                        <i class="fas fa-arrows-alt text-[10px]"></i>
                                    </button>
                                @endif
                                <button
                                    x-on:click="confirmDelete = {{ json_encode($file) }}"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition-colors"
                                    title="Delete"
                                >
                                    <i class="fas fa-trash text-[10px]"></i>
                                </button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Selection Footer --}}
        @if ($this->selectedFile)
            <div
                class="sticky bottom-0 left-0 right-0 bg-white/95 backdrop-blur-sm border-t border-gray-100 py-3.5 mt-4 z-20 shadow-[0_-8px_20px_-6px_rgba(0,0,0,0.1)] -mx-4 px-4 sm:-mx-6 sm:px-6"
            >
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-check-circle text-blue-500"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-800 truncate">{{ $this->selectedFile['name'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ $this->selectedFile['mimeType'] ?? 'Unknown type' }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <button wire:click="resetSelection" class="btn btn-secondary btn-sm">Cancel</button>
                        <button wire:click="confirmSelection" class="btn btn-primary btn-sm">
                            <i class="fas fa-check mr-1"></i> Select File
                        </button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Delete Confirmation --}}
        <template x-if="confirmDelete">
            <div
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
                x-on:click.self="confirmDelete = null"
                x-on:keydown.escape.window="confirmDelete = null"
            >
                <div class="modal-box w-full max-w-sm mx-4">
                    <div class="p-6 text-center">
                        <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-trash-alt text-red-600 text-xl"></i>
                        </div>
                        <h3 class="font-bold text-gray-900">Delete this file?</h3>
                        <p class="text-sm text-gray-500 mt-2" x-text="confirmDelete?.name"></p>
                        <p class="text-xs text-red-500 mt-2">This action cannot be undone.</p>
                    </div>
                    <div class="flex justify-center gap-2 px-6 pb-6">
                        <button x-on:click="confirmDelete = null" class="btn btn-secondary btn-sm">Cancel</button>
                        <button
                            x-on:click="
                                $wire.deleteFile(confirmDelete);
                                confirmDelete = null;
                            "
                            class="btn btn-danger btn-sm"
                        >
                            <i class="fas fa-trash mr-1"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </template>

        {{-- Move Modal --}}
        @if ($this->showMoveModal)
            @php
                $moveFolders = $this->search
                    ? collect($this->files)->filter(fn ($f) => ($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder')
                    : collect($this->files)->filter(fn ($f) => ($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder' && $f['id'] !== $this->movingFileId);
            @endphp
            <div
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
                wire:click.self="$set('showMoveModal', false)"
                x-on:keydown.escape.window="$wire.set('showMoveModal', false)"
            >
                <div class="modal-box w-full max-w-md mx-4">
                    <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                        <h3 class="font-bold text-gray-900">Move "{{ $this->movingFileName }}"</h3>
                        <button wire:click="$set('showMoveModal', false)" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="p-4 max-h-64 overflow-y-auto">
                        <button
                            wire:click="moveToFolder('root')"
                            class="w-full text-left flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50 transition-colors"
                        >
                            <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center">
                                <i class="fab fa-google-drive text-blue-500 text-sm"></i>
                            </div>
                            <span class="text-sm font-medium text-gray-700">My Drive (Root)</span>
                        </button>
                        @foreach ($moveFolders as $folder)
                            <button
                                wire:click="moveToFolder('{{ $folder['id'] }}')"
                                class="w-full text-left flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50 transition-colors"
                            >
                                <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center">
                                    <i class="fas fa-folder text-amber-500 text-sm"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-700">{{ $folder['name'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
