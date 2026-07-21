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
        <div class="space-y-4">
            <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-[var(--brand)] transition-colors"
                 x-data="{ dragging: false }"
                 x-on:dragover.prevent="dragging = true"
                 x-on:dragleave="dragging = false"
                 x-on:drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files; $wire.set('files', Array.from($event.dataTransfer.files))">
                <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                <p class="text-gray-600 font-medium">Drag & drop files here</p>
                <p class="text-sm text-gray-500 mt-1">or click to browse</p>
                <input type="file" wire:model="files" x-ref="fileInput" multiple class="hidden" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt">
                <label class="btn btn-secondary mt-4 cursor-pointer">
                    <i class="fas fa-folder-open"></i> Browse Files
                    <input type="file" wire:model="files" multiple class="hidden" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt">
                </label>
            </div>

            @if(!empty($files))
                <div class="space-y-2">
                    <p class="text-sm font-medium text-gray-700">{{ count($files) }} file(s) pending:</p>
                    @foreach($files as $i => $file)
                        <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-file text-gray-400"></i>
                                <span class="text-sm">{{ $file->getClientOriginalName() }}</span>
                                <span class="text-xs text-gray-500">{{ round($file->getSize() / 1024, 1) }} KB</span>
                            </div>
                            <button wire:click="removeFile({{ $i }})" class="text-gray-400 hover:text-red-500"><i class="fas fa-times"></i></button>
                        </div>
                    @endforeach
                </div>
            @endif

            <div>
                <label class="form-label">Tags (comma-separated)</label>
                <input type="text" wire:model="tags" class="form-input" placeholder="e.g. banner, client-a, urgent">
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$parent.$set('showUpload', false)" class="btn btn-secondary">Cancel</button>
                <button wire:click="upload" class="btn btn-primary" {{ empty($files) ? 'disabled' : '' }}>
                    <i class="fas fa-upload"></i> Upload
                </button>
            </div>
        </div>
        blade;
    }
}
