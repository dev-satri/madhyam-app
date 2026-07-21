<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component
{
    public int $fileId = 0;

    public function getFile()
    {
        return DB::table('files')
            ->leftJoin('file_expiries', 'files.id', '=', 'file_expiries.file_id')
            ->leftJoin('users', 'files.uploaded_by', '=', 'users.id')
            ->where('files.id', $this->fileId)
            ->select('files.*', 'file_expiries.expiry_date', 'file_expiries.extended', 'users.name as uploader_name')
            ->first();
    }

    public function getDownloadUrl(): ?string
    {
        $file = $this->getFile();
        if (!$file || !Storage::exists($file->path)) return null;
        return Storage::url($file->path);
    }

    public function deleteFile(): void
    {
        $file = $this->getFile();
        if ($file) {
            if (Storage::exists($file->path)) {
                Storage::delete($file->path);
            }
            DB::table('files')->where('id', $this->fileId)->delete();
            DB::table('file_expiries')->where('file_id', $this->fileId)->delete();
            $this->dispatch('toast', message: 'File deleted', type: 'success');
            $this->dispatch('file-deleted');
        }
    }

    public function render(): mixed    {
        $file = $this->getFile();
        $downloadUrl = $this->getDownloadUrl();

        if (!$file) return '<div class="text-center py-8 text-gray-500">File not found</div>';

        $daysLeft = $file->expiry_date ? now()->diffInDays($file->expiry_date, false) : null;
        $expiryClass = $daysLeft !== null ? ($daysLeft < 0 ? 'badge-danger' : ($daysLeft <= 3 ? 'badge-warning' : 'badge-success')) : '';
        $expiryLabel = $daysLeft !== null ? ($daysLeft < 0 ? 'Expired' : ceil($daysLeft) . ' days left') : '';

        return <<<'blade'
        <div class="space-y-4">
            {{-- Preview Area --}}
            @if($file->type === 'image')
                <div class="bg-gradient-to-br from-blue-50 to-purple-50 rounded-lg h-64 flex items-center justify-center">
                    @if($downloadUrl)
                        <img src="{{ $downloadUrl }}" alt="{{ $file->name }}" class="max-h-full max-w-full object-contain rounded">
                    @else
                        <i class="fas fa-image text-6xl text-blue-300"></i>
                    @endif
                </div>
            @elseif($file->type === 'video')
                <div class="bg-black rounded-lg h-64 flex items-center justify-center relative">
                    @if($downloadUrl)
                        <video src="{{ $downloadUrl }}" controls class="max-h-full rounded"></video>
                    @else
                        <div class="text-center">
                            <i class="fas fa-play-circle text-white text-5xl mb-2"></i>
                            <p class="text-gray-400 text-sm">Video preview unavailable</p>
                        </div>
                    @endif
                </div>
            @elseif($file->type === 'audio')
                <div class="bg-gradient-to-br from-purple-500 to-purple-700 rounded-lg h-48 flex items-center justify-center">
                    <div class="text-center">
                        <i class="fas fa-headphones text-white text-5xl mb-3"></i>
                        @if($downloadUrl)
                            <audio src="{{ $downloadUrl }}" controls class="mx-auto"></audio>
                        @else
                            <p class="text-purple-200 text-sm">Audio preview unavailable</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="bg-gray-50 rounded-lg h-48 flex items-center justify-center">
                    <div class="text-center">
                        <i class="fas fa-file-alt text-gray-400 text-5xl mb-2"></i>
                        <p class="text-gray-500 text-sm">{{ strtoupper(pathinfo($file->name, PATHINFO_EXTENSION)) }} Document</p>
                    </div>
                </div>
            @endif

            {{-- Metadata --}}
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div><span class="text-gray-500">Type:</span> <span class="badge badge-info">{{ ucfirst($file->type) }}</span></div>
                <div><span class="text-gray-500">Size:</span> {{ round($file->size / 1024, 1) }} KB</div>
                <div><span class="text-gray-500">Uploaded by:</span> {{ $file->uploader_name ?? 'Unknown' }}</div>
                <div><span class="text-gray-500">Date:</span> {{ $file->created_at?->format('M d, Y') ?? 'Unknown' }}</div>
                @if($file->expiry_date)
                    <div><span class="text-gray-500">Expiry:</span> <span class="badge {{ $expiryClass }}">{{ $expiryLabel }}</span></div>
                @endif
            </div>

            @if($file->tags)
                <div class="flex gap-1 flex-wrap">
                    @foreach(explode(',', $file->tags) as $tag)
                        <span class="text-xs bg-gray-100 px-2 py-1 rounded">{{ trim($tag) }}</span>
                    @endforeach
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex justify-end gap-2 pt-2 border-t">
                @if($downloadUrl)
                    <a href="{{ $downloadUrl }}" download class="btn btn-primary"><i class="fas fa-download"></i> Download</a>
                @endif
                <button wire:click="deleteFile" wire:confirm="Are you sure you want to delete this file?" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
        blade;
    }
}
