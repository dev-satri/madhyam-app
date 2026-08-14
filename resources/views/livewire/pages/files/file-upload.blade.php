<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

new class extends Component
{
    use WithFileUploads;

    public $files = [];
    public string $tags = '';
    public int $folderId = 0;

    public function mount(int $folderId = 0): void
    {
        $this->folderId = $folderId;
    }

    public function updatedFiles(): void
    {
        foreach ($this->files as $file) {
            if (!$file->isValid()) {
                $this->dispatch('toast', message: 'Invalid file: ' . $file->getClientOriginalName(), type: 'error');
            }
        }
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function upload(): void
    {
        if (empty($this->files)) {
            $this->dispatch('toast', message: 'No files selected', type: 'error');
            return;
        }

        $retentionDays = DB::table('settings')->value('file_retention_days') ?? 5;

        foreach ($this->files as $file) {
            if (!$file->isValid()) continue;

            $ext = strtolower($file->getClientOriginalExtension());
            $type = match(true) {
                in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'image',
                in_array($ext, ['mp4','mov','avi','mkv']) => 'video',
                in_array($ext, ['mp3','wav','ogg']) => 'audio',
                default => 'document',
            };

            $path = $file->store('files/' . now()->format('Y/m'), 'public');

            $fileId = DB::table('files')->insertGetId([
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'type' => $type,
                'size' => $file->getSize(),
                'folder_id' => $this->folderId ?: null,
                'tags' => $this->tags ?: null,
                'uploaded_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('file_expiries')->insert([
                'file_id' => $fileId,
                'expiry_date' => now()->addDays($retentionDays),
                'extended' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->files = [];
        $this->tags = '';
        $this->dispatch('toast', message: count($this->files) . ' files uploaded', type: 'success');
        $this->dispatch('upload-complete');
    }

    public function render(): mixed    {
        return <<<'blade'
        <div class="space-y-4"
             x-data="{
                 dragging: false,
                 uploadProgress: 0,
                 uploading: false,
                 uploadComplete: false,
                 uploadBytes: 0,
                 uploadTotal: 0,
                 uploadStartTime: 0,
                 uploadLastTime: 0,
                 uploadLastB: 0,
                 uploadSpeed: 0,
                 uploadEta: 0,
                 formatBytes(bytes) {
                     if (bytes === 0) return '0 B';
                     const k = 1024;
                     const sizes = ['B', 'KB', 'MB', 'GB'];
                     const i = Math.floor(Math.log(bytes) / Math.log(k));
                     return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
                 },
                 formatSpeed(bps) {
                     if (bps <= 0) return '—';
                     if (bps >= 1048576) return (bps / 1048576).toFixed(1) + ' MB/s';
                     if (bps >= 1024) return (bps / 1024).toFixed(0) + ' KB/s';
                     return bps.toFixed(0) + ' B/s';
                 },
                 formatEta(seconds) {
                     if (seconds <= 0 || !isFinite(seconds)) return '—';
                     if (seconds < 60) return '~' + Math.ceil(seconds) + 's';
                     if (seconds < 3600) return '~' + Math.floor(seconds / 60) + 'm ' + Math.ceil(seconds % 60) + 's';
                     return '~' + Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
                 },
                 calcSpeed() {
                     const now = Date.now();
                     const elapsed = (now - this.uploadLastTime) / 1000;
                     if (elapsed > 0.3 && this.uploadBytes > this.uploadLastB) {
                         this.uploadSpeed = (this.uploadBytes - this.uploadLastB) / elapsed;
                         const remaining = this.uploadTotal - this.uploadBytes;
                         this.uploadEta = this.uploadSpeed > 0 ? remaining / this.uploadSpeed : 0;
                         this.uploadLastB = this.uploadBytes;
                         this.uploadLastTime = now;
                     }
                 }
             }"
             x-on:livewire-upload-start.window="
                 if ($event.target.getAttribute('wire:model') === 'files') {
                     uploading = true;
                     uploadComplete = false;
                     uploadProgress = 0;
                     const filesList = $event.target.files;
                     let total = 0;
                     for (let i = 0; i < filesList.length; i++) {
                         total += filesList[i].size;
                     }
                     uploadTotal = total;
                     uploadBytes = 0;
                     uploadStartTime = Date.now();
                     uploadLastTime = Date.now();
                     uploadLastB = 0;
                     uploadSpeed = 0;
                     uploadEta = 0;
                 }
             "
             x-on:livewire-upload-progress.window="
                 if ($event.target.getAttribute('wire:model') === 'files') {
                     uploadProgress = Math.round($event.detail.progress);
                     uploadBytes = Math.round((uploadProgress / 100) * uploadTotal);
                     calcSpeed();
                     uploading = true;
                 }
             "
             x-on:livewire-upload-finish.window="
                 if ($event.target.getAttribute('wire:model') === 'files') {
                     uploadProgress = 100;
                     uploadBytes = uploadTotal;
                     uploading = false;
                     uploadComplete = true;
                     uploadSpeed = 0;
                     uploadEta = 0;
                 }
             "
             x-on:livewire-upload-cancel.window="
                 if ($event.target.getAttribute('wire:model') === 'files') {
                     uploading = false;
                     uploadProgress = 0;
                     uploadComplete = false;
                     uploadSpeed = 0;
                     uploadEta = 0;
                 }
             "
             x-on:livewire-upload-error.window="
                 if ($event.target.getAttribute('wire:model') === 'files') {
                     uploading = false;
                     uploadProgress = 0;
                     uploadComplete = false;
                     uploadSpeed = 0;
                     uploadEta = 0;
                 }
             "
        >
            {{-- Upload Progress Bar --}}
            <div x-show="uploading" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-3">
                <div class="bg-gradient-to-br from-[rgba(var(--brand-rgb),0.04)] to-[rgba(var(--brand-rgb),0.08)] border border-[rgba(var(--brand-rgb),0.15)] rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-cloud-upload-alt text-[var(--brand)] text-lg upload-icon-spin"></i>
                            <span class="text-sm font-semibold text-gray-800">Uploading files...</span>
                        </div>
                        <span class="text-sm font-bold tabular-nums" :class="uploadProgress >= 100 ? 'text-green-600' : 'text-[var(--brand)]'" x-text="uploadProgress + '%'"></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden mb-2">
                        <div class="h-full rounded-full transition-all duration-300 ease-out relative overflow-hidden"
                             :class="uploadProgress >= 100 ? 'bg-green-500' : 'bg-gradient-to-r from-[var(--brand)] via-[rgba(var(--brand-rgb),0.8)] to-[var(--brand)]'"
                             :style="'width:' + uploadProgress + '%'">
                            <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/30 to-transparent upload-shimmer" x-show="uploadProgress < 100"></div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-500 tabular-nums">
                                <span x-text="formatBytes(uploadBytes)"></span> / <span x-text="formatBytes(uploadTotal)"></span>
                            </span>
                            <span class="inline-flex items-center gap-1 text-[11px] text-gray-500 font-medium tabular-nums" x-show="uploading && uploadProgress < 100">
                                <i class="fas fa-bolt text-amber-400 text-[9px]"></i>
                                <span x-text="formatSpeed(uploadSpeed)"></span>
                            </span>
                            <span class="inline-flex items-center gap-1 text-[11px] text-gray-500 font-medium tabular-nums" x-show="uploading && uploadProgress < 100">
                                <i class="fas fa-clock text-gray-400 text-[9px]"></i>
                                <span x-text="formatEta(uploadEta)"></span>
                            </span>
                        </div>
                        <button type="button" @click="$wire.cancelUpload('files')" class="text-xs text-red-500 hover:text-red-700 font-medium transition-colors">
                            <i class="fas fa-times mr-1"></i>Cancel
                        </button>
                    </div>
                </div>

            {{-- Drop Zone (hidden during upload) --}}
            <div x-show="!uploading"
                 class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-[var(--brand)] transition-colors"
                 x-on:dragover.prevent="dragging = true"
                 x-on:dragleave="dragging = false"
                 x-on:drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files"
                 x-bind:class="dragging ? 'border-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : ''"
            >
                <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                <p class="text-gray-600 font-medium">Drag & drop files here</p>
                <p class="text-sm text-gray-500 mt-1">or click to browse</p>
                <input type="file" wire:model="files" x-ref="fileInput" multiple class="hidden" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt">
                <label class="btn btn-secondary mt-4 cursor-pointer">
                    <i class="fas fa-folder-open"></i> Browse Files
                </label>
            </div>

            @error('files') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

            {{-- Pending Files List --}}
            @if(!empty($files))
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium text-gray-700">{{ count($files) }} file(s) pending</p>
                        <template x-if="!uploading">
                            <button type="button" @click="$wire.set('files', [])" class="text-xs text-red-400 hover:text-red-600 transition-colors">Clear all</button>
                        </template>
                    </div>
                    @foreach($files as $i => $file)
                        @php
                            $ext = strtolower($file->getClientOriginalExtension());
                            $iconClass = match(true) {
                                in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'fa-file-image text-blue-500 bg-blue-50',
                                in_array($ext, ['mp4','mov','avi','mkv']) => 'fa-file-video text-purple-500 bg-purple-50',
                                in_array($ext, ['mp3','wav','ogg']) => 'fa-file-audio text-pink-500 bg-pink-50',
                                $ext === 'pdf' => 'fa-file-pdf text-red-500 bg-red-50',
                                in_array($ext, ['doc','docx']) => 'fa-file-word text-blue-600 bg-blue-50',
                                default => 'fa-file text-gray-400 bg-gray-100',
                            };
                        @endphp
                        <div class="flex items-center gap-2.5 bg-gray-50 hover:bg-gray-100 rounded-lg px-3 py-2.5 transition-colors group">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 {{ $iconClass }}">
                                <i class="fas {{ explode(' ', $iconClass)[0] }} text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate">{{ $file->getClientOriginalName() }}</p>
                                <p class="text-[11px] text-gray-400 font-mono">{{ round($file->getSize() / 1024, 1) }} KB</p>
                            </div>
                            <template x-if="!uploading">
                                <button wire:click="removeFile({{ $i }})" class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-300 hover:text-red-500 hover:bg-red-50 transition-all opacity-0 group-hover:opacity-100"><i class="fas fa-times text-xs"></i></button>
                            </template>
                        </div>
                    @endforeach
                </div>
            @endif

            <div x-show="!uploading">
                <label class="form-label">Tags (comma-separated)</label>
                <input type="text" wire:model="tags" class="form-input" placeholder="e.g. banner, client-a, urgent">
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$parent.$set('showUpload', false)" class="btn btn-secondary">Cancel</button>
                <button wire:click="upload" class="btn btn-primary" x-bind:disabled="empty(@js($files)) || uploading" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="upload"><i class="fas fa-upload"></i> Upload</span>
                    <span wire:loading wire:target="upload"><i class="fas fa-spinner fa-spin"></i> Uploading...</span>
                </button>
            </div>
        </div>
        blade;
    }
}
