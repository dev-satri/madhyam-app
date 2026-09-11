<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Services\PackageService;
use App\Models\GoogleDriveConnection;
use App\Models\Folder;
use App\Models\File;
use App\Services\GoogleDriveService;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $search = '';
    public string $sortBy = 'name';
    public string $sortDir = 'asc';
    public int $currentFolderId = 0;
    public bool $showUpload = false;
    public bool $showFolderForm = false;
    public bool $showPreview = false;
    public int $previewFileId = 0;
    public string $folderName = '';
    public int $folderClientId = 0;
    public $pendingFiles = [];
    public string $uploadTags = '';
    public int $editingFolderId = 0;
    public string $viewMode = 'list';
    public string $folderViewMode = 'grid';
    public string $uploadMode = 'local';
    public string $externalUrl = '';
    public string $externalFileName = '';
    public string $externalFileType = 'document';
    public bool $googleDriveConnected = false;
    public bool $showDriveBrowser = false;
    public bool $folderMode = false;
    public string $uploadFolderName = '';
    public bool $folderUploading = false;
    public int $folderProgressCurrent = 0;
    public int $folderProgressTotal = 0;
    public string $folderProgressFile = '';
    public array $folderErrors = [];
    public bool $folderCancelled = false;
    public bool $driveUploading = false;
    public int $driveProgressCurrent = 0;
    public int $driveProgressTotal = 0;
    public string $driveProgressFile = '';
    public int $driveProgressPercent = 0;

    // Supported file types configuration
    private const SUPPORTED_EXTENSIONS = [
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'ico', 'tiff', 'tif',
        // Videos
        'mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'mpeg', 'mpg', '3gp', 'm4v',
        // Audio
        'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'opus',
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp',
        // Archives
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
        // Code & Data
        'json', 'xml', 'csv', 'sql', 'html', 'css', 'js', 'php', 'py', 'java', 'cpp', 'c', 'h',
        // Other
        'eps', 'ai', 'psd', 'sketch', 'fig',
    ];

    private function validateFileTypes(): bool
    {
        if (empty($this->pendingFiles)) {
            return true;
        }

        $invalidFiles = [];
        foreach ($this->pendingFiles as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, self::SUPPORTED_EXTENSIONS)) {
                $invalidFiles[] = $file->getClientOriginalName() . " (.{$ext})";
            }
        }

        if (!empty($invalidFiles)) {
            $fileList = implode(', ', array_slice($invalidFiles, 0, 3));
            if (count($invalidFiles) > 3) {
                $fileList .= ' and ' . (count($invalidFiles) - 3) . ' more';
            }

            $supportedList = implode(', ', array_slice(self::SUPPORTED_EXTENSIONS, 0, 20));
            $supportedList .= '... and more';

            $this->dispatch('toast', 
                message: "Unsupported file type(s): {$fileList}. Supported formats: {$supportedList}", 
                type: 'error'
            );
            return false;
        }

        return true;
    }

    public function mount(): void
    {
        $this->currentFolderId = request()->query('folder', 0);
        $connection = GoogleDriveConnection::getActive();
        $this->googleDriveConnected = $connection && $connection->isActive();
    }

    public function updatedPendingFiles(): void
    {
        if ($this->folderMode && ! empty($this->pendingFiles)) {
            $first = $this->pendingFiles[0];
            if (method_exists($first, 'getClientOriginalPath')) {
                $path = $first->getClientOriginalPath();
                $parts = explode('/', $path);
                $this->uploadFolderName = $parts[0] ?? 'Selected folder';
            }
        }
    }

    #[Computed]
    public function storageInfo(): array
    {
        return $this->getStorageInfo();
    }

    #[Computed]
    public function driveQuota(): ?array
    {
        if (! $this->googleDriveConnected) {
            return null;
        }

        try {
            return app(GoogleDriveService::class)->getStorageQuota();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getStorageInfo(): array
    {
        $clientId = $this->folderClientId;
        if (! $clientId) {
            $totalSize = DB::table('files')->whereNull('deleted_at')->sum('size');
            return [
                'used_bytes' => $totalSize,
                'used_mb' => round($totalSize / 1048576, 2),
                'limit_mb' => 5120,
                'percent' => min(100, (int) round(($totalSize / 1048576 / 5120) * 100)),
                'is_over' => ($totalSize / 1048576) > 5120,
                'is_near' => ($totalSize / 1048576 / 5120) >= 0.8,
                'client_name' => null,
            ];
        }

        $usage = PackageService::getUsage($clientId);
        $limits = PackageService::getLimits($clientId);
        $usedMb = round(($usage['storage_used_bytes'] ?? 0) / 1048576, 2);
        $limitMb = $limits['storage_limit_mb'] ?? 5120;
        $pct = PackageService::getUsagePercent((int) $usedMb, $limitMb);
        $client = DB::table('clients')->whereNull('deleted_at')->where('id', $clientId)->first();

        return [
            'used_bytes' => $usage['storage_used_bytes'] ?? 0,
            'used_mb' => $usedMb,
            'limit_mb' => $limitMb,
            'percent' => $pct,
            'is_over' => $pct >= 100,
            'is_near' => $pct >= 80,
            'client_name' => $client?->name,
        ];
    }

    #[Computed]
    public function breadcrumbs(): array
    {
        return $this->getBreadcrumbs();
    }

    #[Computed]
    public function folders()
    {
        return $this->getFolders();
    }

    #[Computed]
    public function files()
    {
        return $this->getFiles();
    }

    #[Computed]
    public function stats(): array
    {
        return $this->getStats();
    }

    #[Computed]
    public function clients()
    {
        return DB::table('clients')->whereNull('deleted_at')->where('status', 'active')->orderBy('name')->get();
    }

    public function getBreadcrumbs(): array
    {
        $crumbs = [['id' => 0, 'name' => config('app.name', 'Madhyam')]];
        $current = $this->currentFolderId;
        while ($current) {
            $folder = DB::table('folders')->where('id', $current)->first();
            if ($folder) {
                array_unshift($crumbs, ['id' => $folder->id, 'name' => $folder->name]);
                $current = $folder->parent_id ?? 0;
            } else {
                break;
            }
        }
        return $crumbs;
    }

    public function getFolders()
    {
        $query = DB::table('folders');
        if ($this->currentFolderId) {
            $query->where('parent_id', $this->currentFolderId);
        } else {
            $query->whereNull('parent_id');
        }
        $folders = $query->orderBy('name')->get();

        $folderIds = $folders->pluck('id')->toArray();
        if (!empty($folderIds)) {
            $fileStats = DB::table('files')
                ->whereIn('folder_id', $folderIds)
                ->whereNull('deleted_at')
                ->selectRaw('folder_id, COUNT(*) as file_count, COALESCE(SUM(size), 0) as total_size')
                ->groupBy('folder_id')
                ->get()
                ->keyBy('folder_id');

            $subfolderCounts = DB::table('folders')
                ->whereIn('parent_id', $folderIds)
                ->selectRaw('parent_id, COUNT(*) as cnt')
                ->groupBy('parent_id')
                ->get()
                ->keyBy('parent_id');
        } else {
            $fileStats = collect();
            $subfolderCounts = collect();
        }

        return $folders->map(function ($f) use ($fileStats, $subfolderCounts) {
            $f->file_count = $fileStats[$f->id]->file_count ?? 0;
            $f->total_size = $fileStats[$f->id]->total_size ?? 0;
            $f->subfolder_count = $subfolderCounts[$f->id]->cnt ?? 0;
            $f->total_size_label = $this->formatSize($f->total_size);
            return $f;
        });
    }

    public function getFiles()
    {
        $q = DB::table('files')
            ->leftJoin('file_expiries', 'files.id', '=', 'file_expiries.file_id')
            ->whereNull('files.deleted_at');

        if ($this->currentFolderId) {
            $q->where('files.folder_id', $this->currentFolderId);
        } else {
            $q->whereNull('files.folder_id');
        }

        if ($this->search) {
            $q->where(function ($q) {
                $q->where('files.name', 'like', "%{$this->search}%")
                  ->orWhere('files.tags', 'like', "%{$this->search}%")
                  ->orWhere('files.type', 'like', "%{$this->search}%");
            });
        }

        return $q->select('files.*', 'file_expiries.expiry_date', 'file_expiries.extended')
            ->orderBy(match($this->sortBy) {
                'name' => 'files.name',
                'date' => 'files.created_at',
                'size' => 'files.size',
                'type' => 'files.type',
                default => 'files.name',
            }, $this->sortDir)
            ->get()
            ->map(function ($f) {
                $f->size_label = $this->formatSize($f->size);
                $f->type_icon = match($f->type) {
                    'image' => 'fa-file-image',
                    'video' => 'fa-file-video',
                    'audio' => 'fa-file-audio',
                    default => 'fa-file',
                };
                $f->type_color = match($f->type) {
                    'image' => 'text-blue-500',
                    'video' => 'text-purple-500',
                    'audio' => 'text-pink-500',
                    default => 'text-gray-400',
                };
                $f->ext = strtoupper(pathinfo($f->name, PATHINFO_EXTENSION));
                if ($f->expiry_date) {
                    $days = now()->diffInDays($f->expiry_date, false);
                    $f->expiry_label = $days < 0 ? 'Expired' : ceil($days) . 'd left';
                    $f->expiry_class = $days < 0 ? 'badge-danger' : ($days <= 3 ? 'badge-warning' : 'badge-success');
                }
                return $f;
            });
    }

    public function getStats(): array
    {
        $files = DB::table('files')->whereNull('deleted_at');
        $totalSize = (clone $files)->sum('size');
        $expiring = DB::table('file_expiries')
            ->where('expiry_date', '<=', now()->addDays(3))
            ->where('expiry_date', '>=', now())
            ->count();
        return [
            'total' => (clone $files)->count(),
            'storage' => $this->formatSize($totalSize),
            'expiring' => $expiring,
            'images' => (clone $files)->where('type', 'image')->count(),
            'videos' => (clone $files)->where('type', 'video')->count(),
            'audio' => (clone $files)->where('type', 'audio')->count(),
            'documents' => (clone $files)->where('type', 'document')->count(),
        ];
    }

    public function enterFolder(int $id): void
    {
        $this->currentFolderId = $id;
        $this->search = '';
    }

    public function openFolderForm(?int $id = null): void
    {
        if ($id) {
            $folder = DB::table('folders')->where('id', $id)->first();
            if ($folder) {
                $this->editingFolderId = $id;
                $this->folderName = $folder->name;
            }
        } else {
            $this->editingFolderId = 0;
            $this->folderName = '';
        }
        $this->folderClientId = 0;
        $this->showFolderForm = true;
    }

    public function saveFolder(): void
    {
        $this->validate(['folderName' => 'required|string|max:255']);
        $data = [
            'name' => $this->folderName,
            'parent_id' => $this->currentFolderId ?: null,
            'client_id' => $this->folderClientId ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($this->editingFolderId) {
            DB::table('folders')->where('id', $this->editingFolderId)->update($data);
        } else {
            DB::table('folders')->insert($data);
        }
        $this->showFolderForm = false;
        $this->dispatch('toast', message: 'Folder saved', type: 'success');
    }

    public function deleteFolder(int $id): void
    {
        Folder::findOrFail($id)->delete();
        $this->dispatch('toast', message: 'Folder deleted', type: 'success');
    }

    public function moveFile(int $fileId, int $folderId): void
    {
        $file = DB::table('files')->where('id', $fileId)->first();
        if (!$file) return;

        if ($folderId > 0) {
            $folder = DB::table('folders')->where('id', $folderId)->first();
            if (!$folder) return;
        }

        DB::table('files')->where('id', $fileId)->update([
            'folder_id' => $folderId ?: null,
            'updated_at' => now(),
        ]);
        $this->dispatch('toast', message: 'File moved', type: 'success');
    }

    public function moveFolder(int $folderId, int $newParentId): void
    {
        if ($folderId === $newParentId) return;

        $folder = DB::table('folders')->where('id', $folderId)->first();
        if (!$folder) return;

        if ($newParentId > 0) {
            $target = DB::table('folders')->where('id', $newParentId)->first();
            if (!$target) return;

            $ancestors = collect([$newParentId]);
            $pid = $target->parent_id;
            while ($pid) {
                $ancestors->push($pid);
                $parent = DB::table('folders')->where('id', $pid)->first();
                $pid = $parent ? ($parent->parent_id ?? null) : null;
            }
            if ($ancestors->contains($folderId)) {
                $this->dispatch('toast', message: 'Cannot move folder into its own subfolder', type: 'warning');
                return;
            }
        }

        DB::table('folders')->where('id', $folderId)->update([
            'parent_id' => $newParentId ?: null,
            'updated_at' => now(),
        ]);
        $this->dispatch('toast', message: 'Folder moved', type: 'success');
    }

    public function openUpload(): void
    {
        $this->pendingFiles = [];
        $this->uploadTags = '';
        $this->externalUrl = '';
        $this->externalFileName = '';
        $this->externalFileType = 'document';
        $this->uploadMode = 'local';
        $this->showUpload = true;
    }

    public function setUploadMode(string $mode): void
    {
        $this->uploadMode = $mode;
    }

    public function saveDriveFile(array $driveFile): void
    {
        $url = $driveFile['url'] ?? $driveFile['webViewLink'] ?? '';
        if (! $url) {
            $this->dispatch('toast', message: 'No URL returned from Google Drive', type: 'error');

            return;
        }

        $name = $driveFile['name'] ?? 'Drive File';
        $mimeType = $driveFile['mime'] ?? $driveFile['mimeType'] ?? '';
        $size = $driveFile['size'] ?? 0;
        $driveId = $driveFile['drive_file_id'] ?? $driveFile['id'] ?? null;

        $type = match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'document',
        };

        DB::table('files')->insertGetId([
            'name' => $name,
            'path' => '',
            'type' => $type,
            'size' => is_numeric($size) ? (int) $size : 0,
            'storage_type' => 'external',
            'external_url' => $url,
            'folder_id' => $this->currentFolderId ?: null,
            'client_id' => $this->folderClientId ?: null,
            'tags' => $this->uploadTags ?: null,
            'uploaded_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->showUpload = false;
        $this->uploadTags = '';
        $this->dispatch('toast', message: "File \"{$name}\" added from Google Drive", type: 'success');
    }

    public function saveExternalLink(): void
    {
        $this->validate([
            'externalUrl' => 'required|url|max:500',
            'externalFileName' => 'required|string|max:255',
            'externalFileType' => 'required|in:image,video,audio,document',
        ]);

        $fileId = DB::table('files')->insertGetId([
            'name' => $this->externalFileName,
            'path' => '',
            'type' => $this->externalFileType,
            'size' => 0,
            'storage_type' => 'external',
            'external_url' => $this->externalUrl,
            'folder_id' => $this->currentFolderId ?: null,
            'client_id' => $this->folderClientId ?: null,
            'tags' => $this->uploadTags ?: null,
            'uploaded_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->externalUrl = '';
        $this->externalFileName = '';
        $this->externalFileType = 'document';
        $this->showUpload = false;
        $this->dispatch('toast', message: 'External file link added', type: 'success');
    }

    public function removePendingFile(int $index): void
    {
        unset($this->pendingFiles[$index]);
        $this->pendingFiles = array_values($this->pendingFiles);
    }

    public function uploadFiles(): void
    {
        if (empty($this->pendingFiles)) {
            $this->dispatch('toast', message: 'No files selected', type: 'warning');
            return;
        }

        // Validate file types
        if (!$this->validateFileTypes()) {
            return;
        }

        // Check file sizes before attempting upload (5GB limit per file)
        $maxFileSize = 5 * 1024 * 1024 * 1024; // 5GB in bytes
        foreach ($this->pendingFiles as $file) {
            $fileSize = $file->getSize();
            $fileSizeGB = round($fileSize / (1024 * 1024 * 1024), 2);
            
            if ($fileSize > $maxFileSize) {
                $this->dispatch('toast', message: "File '{$file->getClientOriginalName()}' exceeds the 5GB size limit ({$fileSizeGB}GB). Please reduce the file size and try again.", type: 'error');
                return;
            }
        }

        if ($this->folderClientId) {
            $info = $this->getStorageInfo();
            $totalNewBytes = array_sum(array_map(fn ($f) => $f->getSize(), $this->pendingFiles));
            $totalNewMb = $totalNewBytes / 1048576;

            if (($info['used_mb'] + $totalNewMb) > $info['limit_mb']) {
                $this->dispatch('toast', message: 'Storage quota exceeded. Use "Add Drive Link" for large files.', type: 'error');
                return;
            }
        }

        $retentionDays = DB::table('settings')->value('file_retention_days') ?? 5;
        $uploaded = 0;

        foreach ($this->pendingFiles as $file) {
            try {
                $path = $file->store('files/' . now()->format('Y/m'), 'public');
                $ext = strtolower($file->getClientOriginalExtension());
                $type = match(true) {
                    in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'image',
                    in_array($ext, ['mp4','mov','avi','mkv','webm']) => 'video',
                    in_array($ext, ['mp3','wav','ogg','flac']) => 'audio',
                    default => 'document',
                };

                $fileId = DB::table('files')->insertGetId([
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'type' => $type,
                    'size' => $file->getSize(),
                    'storage_type' => 'local',
                    'folder_id' => $this->currentFolderId ?: null,
                    'client_id' => $this->folderClientId ?: null,
                    'tags' => $this->uploadTags ?: null,
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

                if ($this->folderClientId) {
                    PackageService::recordFile($this->folderClientId, $file->getSize());
                }

                $uploaded++;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('File upload failed', [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                    'size' => $file->getSize(),
                ]);
                $this->dispatch('toast', message: "Failed to upload '{$file->getClientOriginalName()}': {$e->getMessage()}", type: 'error');
                return;
            }
        }

        $this->pendingFiles = [];
        $this->uploadTags = '';
        $this->showUpload = false;
        $this->dispatch('toast', message: "$uploaded file(s) uploaded successfully", type: 'success');
    }

    public function uploadToGoogleDrive(): void
    {
        if (empty($this->pendingFiles)) {
            $this->dispatch('toast', message: 'No files selected', type: 'warning');
            return;
        }

        // Validate file types
        if (!$this->validateFileTypes()) {
            return;
        }

        $connection = GoogleDriveConnection::getActive();
        if (! $connection || ! $connection->isActive()) {
            $this->dispatch('toast', message: 'Google Drive is not connected. Please connect first.', type: 'error');
            return;
        }

        // Check file sizes before attempting upload (5GB limit per file)
        $maxFileSize = 5 * 1024 * 1024 * 1024; // 5GB in bytes
        foreach ($this->pendingFiles as $file) {
            $fileSize = $file->getSize();
            $fileSizeGB = round($fileSize / (1024 * 1024 * 1024), 2);
            
            if ($fileSize > $maxFileSize) {
                $this->dispatch('toast', message: "File '{$file->getClientOriginalName()}' exceeds the 5GB size limit ({$fileSizeGB}GB). Please reduce the file size and try again.", type: 'error');
                return;
            }
        }

        $driveService = app(GoogleDriveService::class);
        $quota = $driveService->getStorageQuota();

        if ($quota && $quota['limit'] > 0) {
            $totalNewBytes = array_sum(array_map(fn ($f) => $f->getSize(), $this->pendingFiles));
            if (($quota['used'] + $totalNewBytes) > $quota['limit']) {
                $this->dispatch('toast', message: 'Google Drive storage quota exceeded. Free up space first.', type: 'error');
                return;
            }
        }

        // Initialize progress tracking
        $this->driveUploading = true;
        $this->driveProgressCurrent = 0;
        $this->driveProgressTotal = count($this->pendingFiles);
        $this->driveProgressFile = '';
        $this->driveProgressPercent = 0;

        $uploaded = 0;
        $errors = 0;
        $errorMessages = [];
        $fileIndex = 0;

        foreach ($this->pendingFiles as $file) {
            $fileIndex++;
            
            // Update progress
            $this->driveProgressCurrent = $fileIndex;
            $this->driveProgressFile = $file->getClientOriginalName();
            $this->driveProgressPercent = round(($fileIndex / $this->driveProgressTotal) * 100);
            
            // Dispatch progress event for real-time UI update
            $this->dispatch('drive-upload-progress', [
                'current' => $this->driveProgressCurrent,
                'total' => $this->driveProgressTotal,
                'file' => $this->driveProgressFile,
                'percent' => $this->driveProgressPercent,
            ]);

            try {
                $ext = strtolower($file->getClientOriginalExtension());
                $type = match(true) {
                    in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'image',
                    in_array($ext, ['mp4','mov','avi','mkv','webm']) => 'video',
                    in_array($ext, ['mp3','wav','ogg','flac']) => 'audio',
                    default => 'document',
                };

                // Use file resource handle instead of loading entire file into memory
                $fileHandle = fopen($file->getRealPath(), 'r');
                if ($fileHandle === false) {
                    throw new \RuntimeException('Unable to open file for reading');
                }

                $driveResult = $driveService->uploadFile(
                    $file->getClientOriginalName(),
                    $fileHandle,
                    null,
                    $file->getMimeType()
                );

                fclose($fileHandle);

                $driveFileId = $driveResult['id'] ?? null;
                $webViewLink = $driveResult['webViewLink'] ?? ("https://drive.google.com/file/d/{$driveFileId}/view");

                DB::table('files')->insertGetId([
                    'name' => $file->getClientOriginalName(),
                    'path' => '',
                    'type' => $type,
                    'size' => $file->getSize(),
                    'storage_type' => 'drive',
                    'external_url' => $webViewLink,
                    'drive_file_id' => $driveFileId,
                    'folder_id' => $this->currentFolderId ?: null,
                    'client_id' => $this->folderClientId ?: null,
                    'tags' => $this->uploadTags ?: null,
                    'uploaded_by' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $uploaded++;
            } catch (\Exception $e) {
                $errors++;
                $errorMessage = $e->getMessage();
                $errorMessages[] = $file->getClientOriginalName();
                \Illuminate\Support\Facades\Log::error('Google Drive upload failed', [
                    'file' => $file->getClientOriginalName(),
                    'error' => $errorMessage,
                    'size' => $file->getSize(),
                ]);
            }
        }

        // Reset progress tracking
        $this->driveUploading = false;
        $this->driveProgressCurrent = 0;
        $this->driveProgressTotal = 0;
        $this->driveProgressFile = '';
        $this->driveProgressPercent = 0;

        $this->pendingFiles = [];
        $this->uploadTags = '';
        $this->showUpload = false;

        if ($errors > 0) {
            $failedFiles = implode(', ', array_slice($errorMessages, 0, 3));
            if (count($errorMessages) > 3) {
                $failedFiles .= ' and ' . (count($errorMessages) - 3) . ' more';
            }
            $this->dispatch('toast', message: "$uploaded file(s) uploaded, $errors failed: {$failedFiles}", type: 'warning');
        } else {
            $this->dispatch('toast', message: "$uploaded file(s) uploaded to Google Drive", type: 'success');
        }
    }

    public function uploadFolderToDrive(): void
    {
        if (empty($this->pendingFiles)) {
            $this->dispatch('toast', message: 'No files selected', type: 'warning');
            return;
        }

        // Validate file types
        if (!$this->validateFileTypes()) {
            return;
        }

        $connection = GoogleDriveConnection::getActive();
        if (! $connection || ! $connection->isActive()) {
            $this->dispatch('toast', message: 'Google Drive is not connected. Please connect first.', type: 'error');
            return;
        }

        // Check file sizes before attempting upload (5GB limit per file)
        $maxFileSize = 5 * 1024 * 1024 * 1024; // 5GB in bytes
        foreach ($this->pendingFiles as $file) {
            $fileSize = $file->getSize();
            $fileSizeGB = round($fileSize / (1024 * 1024 * 1024), 2);
            
            if ($fileSize > $maxFileSize) {
                $this->dispatch('toast', message: "File '{$file->getClientOriginalName()}' exceeds the 5GB size limit ({$fileSizeGB}GB). Please reduce the file size and try again.", type: 'error');
                return;
            }
        }

        $driveService = app(GoogleDriveService::class);
        $quota = $driveService->getStorageQuota();

        if ($quota && $quota['limit'] > 0) {
            $totalNewBytes = array_sum(array_map(fn ($f) => $f->getSize(), $this->pendingFiles));
            if (($quota['used'] + $totalNewBytes) > $quota['limit']) {
                $this->dispatch('toast', message: 'Google Drive storage quota exceeded. Free up space first.', type: 'error');
                return;
            }
        }

        $this->folderUploading = true;
        $this->folderCancelled = false;
        $this->folderErrors = [];
        $this->folderProgressCurrent = 0;
        $this->folderProgressTotal = count($this->pendingFiles);
        $this->folderProgressFile = '';
        $folderPathMap = [];

        $retentionDays = DB::table('settings')->value('file_retention_days') ?? 5;
        $uploaded = 0;

        foreach ($this->pendingFiles as $file) {
            if ($this->folderCancelled) {
                break;
            }

            $relativePath = method_exists($file, 'getClientOriginalPath')
                ? $file->getClientOriginalPath()
                : $file->getClientOriginalName();
            $fileName = $file->getClientOriginalName();

            $this->folderProgressFile = $relativePath;
            $this->dispatch('folder-upload-progress', [
                'current' => $this->folderProgressCurrent,
                'total' => $this->folderProgressTotal,
                'file' => $this->folderProgressFile,
            ]);

            try {
                $parentFolderId = $this->resolveDriveFolder($relativePath, $driveService, $folderPathMap);

                // Use file resource handle instead of loading entire file into memory
                $fileHandle = fopen($file->getRealPath(), 'r');
                if ($fileHandle === false) {
                    throw new \RuntimeException('Unable to open file for reading');
                }

                $driveResult = $driveService->uploadFile(
                    $fileName,
                    $fileHandle,
                    $parentFolderId,
                    $file->getMimeType()
                );

                fclose($fileHandle);

                $driveFileId = $driveResult['id'] ?? null;
                $webViewLink = $driveResult['webViewLink'] ?? ("https://drive.google.com/file/d/{$driveFileId}/view");

                $ext = strtolower($file->getClientOriginalExtension());
                $type = match(true) {
                    in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'image',
                    in_array($ext, ['mp4','mov','avi','mkv','webm']) => 'video',
                    in_array($ext, ['mp3','wav','ogg','flac']) => 'audio',
                    default => 'document',
                };

                DB::table('files')->insertGetId([
                    'name' => $fileName,
                    'path' => '',
                    'type' => $type,
                    'size' => $file->getSize(),
                    'storage_type' => 'drive',
                    'external_url' => $webViewLink,
                    'drive_file_id' => $driveFileId,
                    'folder_id' => $this->currentFolderId ?: null,
                    'client_id' => $this->folderClientId ?: null,
                    'tags' => $this->uploadTags ?: null,
                    'uploaded_by' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('file_expiries')->insert([
                    'file_id' => DB::getPdo()->lastInsertId(),
                    'expiry_date' => now()->addDays($retentionDays),
                    'extended' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($this->folderClientId) {
                    PackageService::recordFile($this->folderClientId, $file->getSize());
                }

                $uploaded++;
                $this->folderProgressCurrent++;
            } catch (\Exception $e) {
                $this->folderErrors[] = $relativePath . ': ' . $e->getMessage();
                \Illuminate\Support\Facades\Log::error('Google Drive folder upload failed', [
                    'file' => $relativePath,
                    'error' => $e->getMessage(),
                    'size' => $file->getSize(),
                ]);
            }
        }

        $this->folderUploading = false;
        $this->folderProgressFile = '';

        $this->dispatch('folder-upload-complete', [
            'uploaded' => $uploaded,
            'errors' => count($this->folderErrors),
            'total' => $this->folderProgressTotal,
        ]);

        if (! $this->folderCancelled && empty($this->folderErrors)) {
            $this->pendingFiles = [];
            $this->uploadTags = '';
            $this->folderMode = false;
            $this->uploadFolderName = '';
            $this->showUpload = false;
            $this->dispatch('toast', message: "$uploaded file(s) uploaded to Google Drive", type: 'success');
        } elseif (! empty($this->folderErrors)) {
            $this->dispatch('toast', message: "$uploaded file(s) uploaded, " . count($this->folderErrors) . " failed", type: 'warning');
        } else {
            $this->dispatch('toast', message: "Upload cancelled. $uploaded file(s) uploaded before cancel.", type: 'warning');
        }
    }

    public function uploadFolderToLocal(): void
    {
        if (empty($this->pendingFiles)) {
            $this->dispatch('toast', message: 'No files selected', type: 'warning');
            return;
        }

        // Validate file types
        if (!$this->validateFileTypes()) {
            return;
        }

        // Check file sizes before attempting upload (5GB limit per file)
        $maxFileSize = 5 * 1024 * 1024 * 1024; // 5GB in bytes
        foreach ($this->pendingFiles as $file) {
            $fileSize = $file->getSize();
            $fileSizeGB = round($fileSize / (1024 * 1024 * 1024), 2);
            
            if ($fileSize > $maxFileSize) {
                $this->dispatch('toast', message: "File '{$file->getClientOriginalName()}' exceeds the 5GB size limit ({$fileSizeGB}GB). Please reduce the file size and try again.", type: 'error');
                return;
            }
        }

        if ($this->folderClientId) {
            $info = $this->getStorageInfo();
            $totalNewBytes = array_sum(array_map(fn ($f) => $f->getSize(), $this->pendingFiles));
            $totalNewMb = $totalNewBytes / 1048576;

            if (($info['used_mb'] + $totalNewMb) > $info['limit_mb']) {
                $this->dispatch('toast', message: 'Storage quota exceeded.', type: 'error');
                return;
            }
        }

        $this->folderUploading = true;
        $this->folderCancelled = false;
        $this->folderErrors = [];
        $this->folderProgressCurrent = 0;
        $this->folderProgressTotal = count($this->pendingFiles);
        $this->folderProgressFile = '';

        $retentionDays = DB::table('settings')->value('file_retention_days') ?? 5;
        $uploaded = 0;

        foreach ($this->pendingFiles as $file) {
            if ($this->folderCancelled) {
                break;
            }

            $relativePath = method_exists($file, 'getClientOriginalPath')
                ? $file->getClientOriginalPath()
                : $file->getClientOriginalName();
            $fileName = $file->getClientOriginalName();

            $this->folderProgressFile = $relativePath;
            $this->dispatch('folder-upload-progress', [
                'current' => $this->folderProgressCurrent,
                'total' => $this->folderProgressTotal,
                'file' => $this->folderProgressFile,
            ]);

            try {
                $path = $file->store('files/' . now()->format('Y/m') . '/' . dirname($relativePath), 'public');

                $ext = strtolower($file->getClientOriginalExtension());
                $type = match(true) {
                    in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'image',
                    in_array($ext, ['mp4','mov','avi','mkv','webm']) => 'video',
                    in_array($ext, ['mp3','wav','ogg','flac']) => 'audio',
                    default => 'document',
                };

                $fileId = DB::table('files')->insertGetId([
                    'name' => $fileName,
                    'path' => $path,
                    'type' => $type,
                    'size' => $file->getSize(),
                    'storage_type' => 'local',
                    'folder_id' => $this->currentFolderId ?: null,
                    'client_id' => $this->folderClientId ?: null,
                    'tags' => $this->uploadTags ?: null,
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

                if ($this->folderClientId) {
                    PackageService::recordFile($this->folderClientId, $file->getSize());
                }

                $uploaded++;
                $this->folderProgressCurrent++;
            } catch (\Exception $e) {
                $this->folderErrors[] = $relativePath . ': ' . $e->getMessage();
                \Illuminate\Support\Facades\Log::error('Folder upload failed', [
                    'file' => $relativePath,
                    'error' => $e->getMessage(),
                    'size' => $file->getSize(),
                ]);
            }
        }

        $this->folderUploading = false;
        $this->folderProgressFile = '';

        $this->dispatch('folder-upload-complete', [
            'uploaded' => $uploaded,
            'errors' => count($this->folderErrors),
            'total' => $this->folderProgressTotal,
        ]);

        if (! $this->folderCancelled && empty($this->folderErrors)) {
            $this->pendingFiles = [];
            $this->uploadTags = '';
            $this->folderMode = false;
            $this->uploadFolderName = '';
            $this->showUpload = false;
            $this->dispatch('toast', message: "$uploaded file(s) uploaded successfully", type: 'success');
        } elseif (! empty($this->folderErrors)) {
            $this->dispatch('toast', message: "$uploaded file(s) uploaded, " . count($this->folderErrors) . " failed", type: 'warning');
        } else {
            $this->dispatch('toast', message: "Upload cancelled. $uploaded file(s) uploaded before cancel.", type: 'warning');
        }
    }

    private function resolveDriveFolder(string $relativePath, GoogleDriveService $driveService, array &$folderPathMap): ?string
    {
        $parts = explode('/', $relativePath);
        array_pop($parts);

        if (empty($parts)) {
            return null;
        }

        $currentParent = null;
        $currentPath = '';

        foreach ($parts as $part) {
            $currentPath = $currentPath ? $currentPath . '/' . $part : $part;

            if (isset($folderPathMap[$currentPath])) {
                $currentParent = $folderPathMap[$currentPath];
                continue;
            }

            $folder = $driveService->createFolder($part, $currentParent);
            $currentParent = $folder['id'];
            $folderPathMap[$currentPath] = $currentParent;
        }

        return $currentParent;
    }

    public function cancelFolderUpload(): void
    {
        $this->folderCancelled = true;
    }

    public function openPreview(int $id): void
    {
        $this->previewFileId = $id;
        $this->showPreview = true;
    }

    public function getPreviewFile()
    {
        $pf = DB::table('files')
            ->leftJoin('file_expiries', 'files.id', '=', 'file_expiries.file_id')
            ->where('files.id', $this->previewFileId)
            ->select('files.*', 'file_expiries.expiry_date', 'file_expiries.extended')
            ->first();

        if ($pf && $pf->expiry_date) {
            $days = now()->diffInDays($pf->expiry_date, false);
            $pf->expiry_label = $days < 0 ? 'Expired' : ceil($days) . 'd left';
            $pf->expiry_class = $days < 0 ? 'badge-danger' : ($days <= 3 ? 'badge-warning' : 'badge-success');
        }

        return $pf;
    }

    public function extendExpiry(int $id): void
    {
        $expiry = DB::table('file_expiries')->where('file_id', $id)->first();
        if ($expiry && !$expiry->extended) {
            DB::table('file_expiries')->where('file_id', $id)->update([
                'expiry_date' => \Carbon\Carbon::parse($expiry->expiry_date)->addDays(5),
                'extended' => true,
            ]);
            $this->dispatch('toast', message: 'Expiry extended by 5 days', type: 'success');
        }
    }

    public function deleteFile(int $id): void
    {
        $file = DB::table('files')->where('id', $id)->first();
        DB::table('file_expiries')->where('file_id', $id)->delete();
        File::findOrFail($id)->delete();
        if ($file) {
            if ($file->drive_file_id && $this->googleDriveConnected) {
                try {
                    app(GoogleDriveService::class)->deleteFile($file->drive_file_id);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to delete Google Drive file', [
                        'file_id' => $id,
                        'drive_file_id' => $file->drive_file_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            if ($file->path && Storage::disk('public')->exists($file->path)) {
                Storage::disk('public')->delete($file->path);
            }
        }
        $this->dispatch('toast', message: 'File deleted', type: 'success');
    }

    public function downloadFile(int $id)
    {
        $file = DB::table('files')->where('id', $id)->first();
        
        if (!$file) {
            $this->dispatch('toast', message: 'File not found', type: 'error');
            return null;
        }

        try {
            // Handle different storage types
            if ($file->storage_type === 'drive' || $file->storage_type === 'external') {
                // For Google Drive and external files, redirect to external URL
                if (!empty($file->external_url)) {
                    return redirect($file->external_url);
                } else {
                    $this->dispatch('toast', message: 'External file URL not available', type: 'error');
                    return null;
                }
            } elseif ($file->storage_type === 'local') {
                // For local files, check if file exists in storage
                if (empty($file->path)) {
                    $this->dispatch('toast', message: 'File path is empty', type: 'error');
                    return null;
                }

                if (!Storage::disk('public')->exists($file->path)) {
                    $this->dispatch('toast', message: 'File not found in storage. It may have been deleted.', type: 'error');
                    \Illuminate\Support\Facades\Log::error('File not found in storage', [
                        'file_id' => $id,
                        'file_name' => $file->name,
                        'file_path' => $file->path,
                    ]);
                    return null;
                }

                // Download the file
                return Storage::disk('public')->download($file->path, $file->name);
            } else {
                $this->dispatch('toast', message: 'Unknown storage type: ' . $file->storage_type, type: 'error');
                return null;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Download failed', [
                'file_id' => $id,
                'file_name' => $file->name ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('toast', message: 'Failed to download file: ' . $e->getMessage(), type: 'error');
            return null;
        }
    }

    public function getFileUrl(int $id): ?string
    {
        $file = DB::table('files')->where('id', $id)->first();
        if (!$file) return null;
        return Storage::disk('public')->url($file->path);
    }

    public function moveFileToFolder(int $fileId, int $folderId): void
    {
        $this->moveFile($fileId, $folderId);
        $this->showPreview = false;
    }

    public function toggleSort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-0">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900">Shared Files</h1>
                    <p class="text-sm text-gray-500">Upload and download files shared across your team &middot; {{ $this->stats['total'] }} files &middot; {{ $this->stats['storage'] }} used</p>
                </div>
                <div class="flex gap-2">
                    <button wire:click="openFolderForm" class="btn btn-secondary btn-sm"><i class="fas fa-folder-plus text-sm"></i> New Folder</button>
                    @if($googleDriveConnected)
                        <button wire:click="$set('showDriveBrowser', true)" class="btn btn-secondary btn-sm"><i class="fab fa-google-drive text-sm"></i> Google Drive</button>
                    @endif
                    <button wire:click="openUpload" class="btn btn-primary btn-sm"><i class="fas fa-cloud-upload-alt text-sm"></i> Upload</button>
                </div>
            </div>

            {{-- Storage Bars --}}
            @php $si = $this->storageInfo; @endphp
            <div class="grid grid-cols-1 {{ $googleDriveConnected ? 'lg:grid-cols-2' : '' }} gap-3 mb-4">
                {{-- Local Storage --}}
                <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-database text-gray-400 text-sm"></i>
                            <span class="text-sm font-semibold text-gray-700">Local Storage</span>
                            @if($si['client_name'])
                                <span class="text-xs text-gray-400">— {{ $si['client_name'] }}</span>
                            @endif
                        </div>
                        <span class="text-sm font-mono {{ $si['is_over'] ? 'text-red-600 font-bold' : ($si['is_near'] ? 'text-amber-600' : 'text-gray-600') }}">
                            {{ $si['used_mb'] }} MB / {{ $si['limit_mb'] }} MB
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div class="h-2.5 rounded-full transition-all duration-500 {{ $si['is_over'] ? 'bg-red-500' : ($si['is_near'] ? 'bg-amber-400' : 'bg-[var(--brand)]') }}"
                             style="width: {{ min(100, $si['percent']) }}%"></div>
                    </div>
                    <div class="flex items-center justify-between mt-1.5">
                        <span class="text-[11px] text-gray-400">{{ $si['percent'] }}% used</span>
                        @if($si['is_over'])
                            <span class="text-[11px] text-red-500 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>Quota exceeded</span>
                        @elseif($si['is_near'])
                            <span class="text-[11px] text-amber-500 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>Nearing limit</span>
                        @endif
                    </div>
                </div>

                {{-- Google Drive Storage --}}
                @if($googleDriveConnected)
                    @php $dq = $this->driveQuota; @endphp
                    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <i class="fab fa-google text-blue-500 text-sm"></i>
                                <span class="text-sm font-semibold text-gray-700">Google Drive</span>
                            </div>
                            @if($dq)
                                <span class="text-sm font-mono {{ $dq['is_over'] ? 'text-red-600 font-bold' : ($dq['is_near'] ? 'text-amber-600' : 'text-gray-600') }}">
                                    @if($dq['limit'] > 0)
                                        {{ $dq['used_gb'] }} GB / {{ $dq['limit_gb'] }} GB
                                    @else
                                        {{ $dq['used_gb'] }} GB / Unlimited
                                    @endif
                                </span>
                            @endif
                        </div>
                        @if($dq)
                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                <div class="h-2.5 rounded-full transition-all duration-500 {{ $dq['is_over'] ? 'bg-red-500' : ($dq['is_near'] ? 'bg-amber-400' : 'bg-blue-500') }}"
                                     style="width: {{ $dq['limit'] > 0 ? min(100, $dq['percent']) : 0 }}%"></div>
                            </div>
                            <div class="flex items-center justify-between mt-1.5">
                                <span class="text-[11px] text-gray-400">
                                    @if($dq['limit'] > 0)
                                        {{ $dq['percent'] }}% used · {{ $dq['free_gb'] }} GB free
                                    @else
                                        {{ $dq['used_gb'] }} GB used · Unlimited
                                    @endif
                                </span>
                                @if($dq['is_over'])
                                    <span class="text-[11px] text-red-500 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>Quota exceeded</span>
                                @elseif($dq['is_near'])
                                    <span class="text-[11px] text-amber-500 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>Nearing limit</span>
                                @endif
                            </div>
                        @else
                            <div class="text-xs text-gray-400 py-1">Unable to load Drive quota</div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Breadcrumb + Search + View Toggle --}}
            <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center mb-4 pb-3 border-b border-gray-200">
                {{-- Breadcrumbs --}}
                <nav class="flex items-center gap-0.5 text-sm flex-1 min-w-0">
                    @foreach($this->breadcrumbs as $i => $crumb)
                        @if($i > 0)<span class="text-gray-300 mx-0.5">›</span>@endif
                        <button wire:click="$set('currentFolderId', {{ $crumb['id'] }})"
                            class="px-1.5 py-0.5 rounded hover:bg-gray-100 truncate max-w-[200px] {{ $crumb['id'] == $currentFolderId ? 'text-[var(--brand)] font-semibold' : 'text-gray-600 hover:text-gray-800' }}">
                            @if($i === 0)<i class="fas fa-home text-xs mr-1"></i>@endif{{ $crumb['name'] }}
                        </button>
                    @endforeach
                </nav>

                <div class="flex gap-2 items-center w-full sm:w-auto">
                    <x-search-input wire="search" placeholder="Search" compact class="w-full sm:w-52" />
                    <select wire:model.live="sortBy" class="form-select py-1.5 text-sm w-auto">
                        <option value="name">Name</option>
                        <option value="date">Date</option>
                        <option value="size">Size</option>
                        <option value="type">Type</option>
                    </select>
                </div>
            </div>

            {{-- Folders Section --}}
            @if($this->folders->count())
                <div class="mb-5">
                    <div class="flex items-center justify-between mb-3 px-1">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-folder text-amber-400 text-xs"></i>
                            <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Folders</span>
                            <span class="text-xs text-gray-400">({{ $this->folders->count() }})</span>
                        </div>
                        <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-0.5">
                            <button wire:click="$set('folderViewMode', 'grid')" class="px-2 py-1 rounded-md text-xs transition-all {{ $folderViewMode === 'grid' ? 'bg-white shadow-sm text-gray-800 font-semibold' : 'text-gray-500 hover:text-gray-700' }}"><i class="fas fa-th-large"></i></button>
                            <button wire:click="$set('folderViewMode', 'list')" class="px-2 py-1 rounded-md text-xs transition-all {{ $folderViewMode === 'list' ? 'bg-white shadow-sm text-gray-800 font-semibold' : 'text-gray-500 hover:text-gray-700' }}"><i class="fas fa-list"></i></button>
                        </div>
                    </div>

                    @if($folderViewMode === 'grid')
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                            @foreach($this->folders as $folder)
                                <div class="group relative flex items-center gap-3 p-3 rounded-xl border-2 border-dashed border-gray-200 bg-white hover:bg-amber-50/60 hover:border-amber-300 cursor-pointer transition-all duration-150 shadow-sm"
                                     wire:click="enterFolder({{ $folder->id }})"
                                     draggable="true"
                                     x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({type:'folder', id:{{ $folder->id }}})); $el.classList.add('opacity-50')"
                                     x-on:dragend="$el.classList.remove('opacity-50')"
                                     x-data="{ hover: false }"
                                     x-on:dragover.prevent="hover = true; $el.classList.add('border-[var(--brand)]', 'bg-blue-50')"
                                     x-on:dragleave="hover = false; $el.classList.remove('border-[var(--brand)]', 'bg-blue-50')"
                                     x-on:drop.prevent="hover = false; $el.classList.remove('border-[var(--brand)]', 'bg-blue-50'); const data = JSON.parse($event.dataTransfer.getData('text/plain')); if(data.type === 'file') $wire.moveFile(data.id, {{ $folder->id }}); if(data.type === 'folder') $wire.moveFolder(data.id, {{ $folder->id }});">
                                    <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                        <i class="fas fa-folder text-amber-400 text-lg"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-gray-800 truncate" title="{{ $folder->name }}">{{ $folder->name }}</div>
                                        <div class="text-[11px] text-gray-400">
                                            @if($folder->file_count > 0){{ $folder->file_count }} file{{ $folder->file_count !== 1 ? 's' : '' }}@endif
                                            @if($folder->subfolder_count > 0) &middot; {{ $folder->subfolder_count }} subfolder{{ $folder->subfolder_count !== 1 ? 's' : '' }}@endif
                                            @if($folder->total_size > 0) &middot; {{ $folder->total_size_label }}@endif
                                        </div>
                                    </div>
                                    <button type="button"
                                        wire:click.stop="$dispatch('open-confirm', { title: 'Delete Folder?', message: 'Delete folder &quot;{{ addslashes($folder->name) }}&quot;? Files inside will not be deleted.', type: 'danger', action: 'deleteFolder', params: [{{ $folder->id }}] })"
                                        aria-label="Delete folder"
                                        class="sm:opacity-0 sm:group-hover:opacity-100 flex-shrink-0 w-7 h-7 rounded flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all">
                                        <i class="fas fa-trash text-xs"></i>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                            {{-- Desktop header --}}
                            <div class="hidden sm:grid grid-cols-12 gap-2 px-4 py-2 bg-gray-50 border-b border-gray-100 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                <div class="col-span-5">Name</div>
                                <div class="col-span-2">Files</div>
                                <div class="col-span-2">Subfolders</div>
                                <div class="col-span-2">Size</div>
                                <div class="col-span-1 text-right">Actions</div>
                            </div>
                            @foreach($this->folders as $folder)
                                {{-- Desktop: grid row --}}
                                <div class="hidden sm:group sm:grid sm:grid-cols-12 sm:gap-2 sm:px-4 sm:py-2.5 sm:border-b sm:border-gray-50 sm:hover:bg-amber-50/50 sm:transition-colors sm:items-center {{ $loop->last ? 'sm:border-b-0' : '' }} sm:cursor-grab sm:active:cursor-grabbing"
                                     draggable="true"
                                     x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({type:'folder', id:{{ $folder->id }}})); $el.classList.add('opacity-50')"
                                     x-on:dragend="$el.classList.remove('opacity-50')"
                                     wire:click="enterFolder({{ $folder->id }})"
                                     x-data="{ hover: false }"
                                     x-on:dragover.prevent="hover = true; $el.classList.add('border-[var(--brand)]', 'bg-blue-50')"
                                     x-on:dragleave="hover = false; $el.classList.remove('border-[var(--brand)]', 'bg-blue-50')"
                                     x-on:drop.prevent="hover = false; $el.classList.remove('border-[var(--brand)]', 'bg-blue-50'); const data = JSON.parse($event.dataTransfer.getData('text/plain')); if(data.type === 'file') $wire.moveFile(data.id, {{ $folder->id }}); if(data.type === 'folder') $wire.moveFolder(data.id, {{ $folder->id }});">
                                    <div class="col-span-5 flex items-center gap-3 min-w-0">
                                        <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center">
                                            <i class="fas fa-folder text-amber-400 text-sm"></i>
                                        </div>
                                        <span class="text-sm font-medium text-gray-800 truncate">{{ $folder->name }}</span>
                                    </div>
                                    <div class="col-span-2 text-xs text-gray-500">{{ $folder->file_count }} file{{ $folder->file_count !== 1 ? 's' : '' }}</div>
                                    <div class="col-span-2 text-xs text-gray-500">{{ $folder->subfolder_count }} subfolder{{ $folder->subfolder_count !== 1 ? 's' : '' }}</div>
                                    <div class="col-span-2 text-xs text-gray-500 font-mono">{{ $folder->total_size_label }}</div>
                                    <div class="col-span-1 flex justify-end opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button type="button"
                                            wire:click.stop="$dispatch('open-confirm', { title: 'Delete Folder?', message: 'Delete folder &quot;{{ addslashes($folder->name) }}&quot;? Files inside will not be deleted.', type: 'danger', action: 'deleteFolder', params: [{{ $folder->id }}] })"
                                            aria-label="Delete folder"
                                            class="w-7 h-7 rounded flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all">
                                            <i class="fas fa-trash text-xs"></i>
                                        </button>
                                    </div>
                                </div>

                                {{-- Mobile: card --}}
                                <div class="sm:hidden px-4 py-3 border-b border-gray-50 {{ $loop->last ? 'border-b-0' : '' }}"
                                     wire:click="enterFolder({{ $folder->id }})">
                                    <div class="flex items-center gap-3 cursor-pointer">
                                        <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                            <i class="fas fa-folder text-amber-400"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-semibold text-gray-800 truncate">{{ $folder->name }}</div>
                                            <div class="text-[11px] text-gray-400">
                                                @if($folder->file_count > 0){{ $folder->file_count }} file{{ $folder->file_count !== 1 ? 's' : '' }}@endif
                                                @if($folder->subfolder_count > 0) &middot; {{ $folder->subfolder_count }} subfolder{{ $folder->subfolder_count !== 1 ? 's' : '' }}@endif
                                                @if($folder->total_size > 0) &middot; {{ $folder->total_size_label }}@endif
                                            </div>
                                        </div>
                                        <button type="button"
                                            wire:click.stop="$dispatch('open-confirm', { title: 'Delete Folder?', message: 'Delete folder &quot;{{ addslashes($folder->name) }}&quot;? Files inside will not be deleted.', type: 'danger', action: 'deleteFolder', params: [{{ $folder->id }}] })"
                                            aria-label="Delete folder"
                                            class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all">
                                            <i class="fas fa-trash text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            {{-- Files Section --}}
            @if($this->files->count())
                <div>
                    <div class="flex items-center gap-2 mb-3 px-1">
                        <i class="fas fa-file text-gray-400 text-xs"></i>
                        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Files</span>
                        <span class="text-xs text-gray-400">({{ $this->files->count() }})</span>
                    </div>

                    {{-- List View --}}
                    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                        {{-- Table Header (desktop only) --}}
                        <div class="hidden sm:grid grid-cols-12 gap-2 px-4 py-2.5 bg-gray-50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                            <div class="col-span-4 cursor-pointer hover:text-gray-700 flex items-center gap-1" wire:click="toggleSort('name')">
                                Name
                                @if($sortBy === 'name')<i class="fas fa-sort-{{ $sortDir === 'asc' ? 'up' : 'down' }} text-[var(--brand)]"></i>@endif
                            </div>
                            <div class="col-span-2 cursor-pointer hover:text-gray-700" wire:click="toggleSort('type')">
                                Type
                                @if($sortBy === 'type')<i class="fas fa-sort-{{ $sortDir === 'asc' ? 'up' : 'down' }} text-[var(--brand)]"></i>@endif
                            </div>
                            <div class="col-span-2 cursor-pointer hover:text-gray-700" wire:click="toggleSort('size')">
                                Size
                                @if($sortBy === 'size')<i class="fas fa-sort-{{ $sortDir === 'asc' ? 'up' : 'down' }} text-[var(--brand)]"></i>@endif
                            </div>
                            <div class="col-span-2 cursor-pointer hover:text-gray-700" wire:click="toggleSort('date')">
                                Modified
                                @if($sortBy === 'date')<i class="fas fa-sort-{{ $sortDir === 'asc' ? 'up' : 'down' }} text-[var(--brand)]"></i>@endif
                            </div>
                            <div class="col-span-2 text-right">Actions</div>
                        </div>

                        {{-- Table Body --}}
                        @foreach($this->files as $file)
                            <div class="group border-b border-gray-50 {{ $loop->last ? 'border-b-0' : '' }} {{ $file->storage_type === 'external' ? 'bg-blue-50/30' : '' }}">

                                {{-- Desktop: grid row --}}
                                <div class="hidden sm:grid grid-cols-12 gap-2 px-4 py-2.5 hover:bg-blue-50/50 transition-colors items-center cursor-grab active:cursor-grabbing"
                                     draggable="true"
                                     x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({type:'file', id:{{ $file->id }}})); $el.classList.add('opacity-50')"
                                     x-on:dragend="$el.classList.remove('opacity-50')">
                                    <div class="col-span-4 flex items-center gap-3 min-w-0 cursor-pointer" wire:click="openPreview({{ $file->id }})">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center
                                            {{ $file->storage_type === 'external' ? 'bg-blue-100' : ($file->type === 'image' ? 'bg-blue-50' : ($file->type === 'video' ? 'bg-purple-50' : ($file->type === 'audio' ? 'bg-pink-50' : 'bg-gray-50'))) }}">
                                            @if($file->storage_type === 'external')
                                                <i class="fab fa-google-drive text-blue-500 text-sm"></i>
                                            @else
                                                <i class="fas {{ $file->type_icon }} {{ $file->type_color }} text-sm"></i>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-sm font-medium text-gray-800 truncate">{{ $file->name }}</span>
                                                @if($file->storage_type === 'external')
                                                    <span class="badge badge-info text-[9px] py-0 px-1.5"><i class="fab fa-google-drive mr-0.5"></i> Drive</span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                @if($file->expiry_date)
                                                    <span class="badge {{ $file->expiry_class }} text-[10px] py-0">{{ $file->expiry_label }}</span>
                                                @endif
                                                @if($file->tags)
                                                    @foreach(array_slice(explode(',', $file->tags), 0, 2) as $tag)
                                                        <span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">{{ trim($tag) }}</span>
                                                    @endforeach
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-span-2">
                                        <span class="text-xs text-gray-500 uppercase">{{ $file->ext ?: $file->type }}</span>
                                    </div>
                                    <div class="col-span-2">
                                        <span class="text-xs text-gray-500 font-mono">{{ $file->size_label }}</span>
                                    </div>
                                    <div class="col-span-2">
                                        <span class="text-xs text-gray-500">{{ $file->created_at ? \App\Support\NepaliDate::display($file->created_at) : '—' }}</span>
                                    </div>
                                    <div class="col-span-2 flex justify-end gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                        @if(!$file->extended && isset($file->expiry_class) && $file->expiry_class !== 'badge-danger')
                                            <button wire:click.stop="extendExpiry({{ $file->id }})" class="w-7 h-7 rounded flex items-center justify-center text-gray-400 hover:text-blue-500 hover:bg-blue-50" title="Extend expiry +5d"><i class="fas fa-clock text-xs"></i></button>
                                        @endif
                                        @if($file->storage_type === 'external')
                                            <a href="{{ $file->external_url }}" target="_blank" rel="noopener noreferrer" class="w-7 h-7 rounded flex items-center justify-center text-gray-400 hover:text-blue-500 hover:bg-blue-50" title="Open in Drive"><i class="fab fa-google-drive text-xs"></i></a>
                                            <button wire:click.stop type="button"
                                                x-data="{ copied: false }"
                                                x-on:click="navigator.clipboard.writeText('{{ $file->external_url }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                class="w-7 h-7 rounded flex items-center justify-center text-gray-400 hover:text-green-500 hover:bg-green-50" title="Copy Drive link">
                                                <i x-show="!copied" class="fas fa-link text-xs"></i>
                                                <i x-show="copied" class="fas fa-check text-xs text-green-500"></i>
                                            </button>
                                        @else
                                            <a href="{{ route('files.download', $file->id) }}" class="w-7 h-7 rounded flex items-center justify-center text-gray-400 hover:text-green-500 hover:bg-green-50" title="Download"><i class="fas fa-download text-xs"></i></a>
                                        @endif
                                        <button type="button" wire:click.stop="$dispatch('open-confirm', { title: 'Delete File?', message: 'Delete &quot;{{ addslashes($file->name) }}&quot;? This cannot be undone.', type: 'danger', action: 'deleteFile', params: [{{ $file->id }}] })" aria-label="Delete file" class="w-7 h-7 rounded flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50" title="Delete"><i class="fas fa-trash text-xs"></i></button>
                                    </div>
                                </div>

                                {{-- Mobile: card layout --}}
                                <div class="sm:hidden px-4 py-3"
                                     draggable="true"
                                     x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({type:'file', id:{{ $file->id }}})); $el.classList.add('opacity-50')"
                                     x-on:dragend="$el.classList.remove('opacity-50')">
                                    <div class="flex items-start gap-3 cursor-pointer" wire:click="openPreview({{ $file->id }})">
                                        <div class="flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center
                                            {{ $file->storage_type === 'external' ? 'bg-blue-100' : ($file->type === 'image' ? 'bg-blue-50' : ($file->type === 'video' ? 'bg-purple-50' : ($file->type === 'audio' ? 'bg-pink-50' : 'bg-gray-50'))) }}">
                                            @if($file->storage_type === 'external')
                                                <i class="fab fa-google-drive text-blue-500"></i>
                                            @else
                                                <i class="fas {{ $file->type_icon }} {{ $file->type_color }}"></i>
                                            @endif
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-sm font-medium text-gray-800 truncate">{{ $file->name }}</span>
                                                @if($file->storage_type === 'external')
                                                    <span class="badge badge-info text-[9px] py-0 px-1.5"><i class="fab fa-google-drive mr-0.5"></i> Drive</span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                                <span class="text-[11px] text-gray-400 font-mono">{{ $file->size_label }}</span>
                                                @if($file->expiry_date)
                                                    <span class="badge {{ $file->expiry_class }} text-[10px] py-0">{{ $file->expiry_label }}</span>
                                                @endif
                                                @if($file->tags)
                                                    @foreach(array_slice(explode(',', $file->tags), 0, 2) as $tag)
                                                        <span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">{{ trim($tag) }}</span>
                                                    @endforeach
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-end gap-1 mt-2 ml-13">
                                        @if(!$file->extended && isset($file->expiry_class) && $file->expiry_class !== 'badge-danger')
                                            <button wire:click.stop="extendExpiry({{ $file->id }})" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-blue-500 hover:bg-blue-50" title="Extend expiry +5d"><i class="fas fa-clock text-sm"></i></button>
                                        @endif
                                        @if($file->storage_type === 'external')
                                            <a href="{{ $file->external_url }}" target="_blank" rel="noopener noreferrer" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-blue-500 hover:bg-blue-50" title="Open in Drive"><i class="fab fa-google-drive text-sm"></i></a>
                                            <button wire:click.stop type="button"
                                                x-data="{ copied: false }"
                                                x-on:click="navigator.clipboard.writeText('{{ $file->external_url }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-green-500 hover:bg-green-50" title="Copy Drive link">
                                                <i x-show="!copied" class="fas fa-link text-sm"></i>
                                                <i x-show="copied" class="fas fa-check text-sm text-green-500"></i>
                                            </button>
                                        @else
                                            <a href="{{ route('files.download', $file->id) }}" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-green-500 hover:bg-green-50" title="Download"><i class="fas fa-download text-sm"></i></a>
                                        @endif
                                        <button type="button" wire:click.stop="$dispatch('open-confirm', { title: 'Delete File?', message: 'Delete &quot;{{ addslashes($file->name) }}&quot;? This cannot be undone.', type: 'danger', action: 'deleteFile', params: [{{ $file->id }}] })" aria-label="Delete file" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50" title="Delete"><i class="fas fa-trash text-sm"></i></button>
                                    </div>
                                </div>

                                {{-- Drive Link Row (visible inline for external files) --}}
                                @if($file->storage_type === 'external' && $file->external_url)
                                    <div class="px-4 pb-2.5 pt-0">
                                        <div class="flex items-center gap-2 ml-12">
                                            <i class="fab fa-google-drive text-blue-400 text-xs flex-shrink-0"></i>
                                            <a href="{{ $file->external_url }}" target="_blank" rel="noopener noreferrer"
                                               class="text-xs text-blue-600 hover:text-blue-800 hover:underline truncate flex-1 min-w-0 font-mono">
                                                {{ $file->external_url }}
                                            </a>
                                            <button type="button"
                                                x-data="{ copied: false }"
                                                x-on:click="navigator.clipboard.writeText('{{ $file->external_url }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                class="flex-shrink-0 text-xs text-gray-400 hover:text-green-500 px-1.5 py-0.5 rounded hover:bg-green-50 transition-colors">
                                                <i x-show="!copied" class="fas fa-copy"></i>
                                                <i x-show="copied" class="fas fa-check text-green-500"></i>
                                                <span x-text="copied ? 'Copied!' : 'Copy'" class="ml-1"></span>
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Empty State --}}
            @if(!$this->folders->count() && !$this->files->count())
                <div class="flex flex-col items-center justify-center py-20 text-center">
                    <div class="w-20 h-20 rounded-2xl bg-gray-100 flex items-center justify-center mb-4">
                        <i class="fas fa-folder-open text-3xl text-gray-300"></i>
                    </div>
                    <p class="text-gray-600 font-medium text-sm mb-1">This folder is empty</p>
                    <p class="text-gray-400 text-xs mb-4">Create a folder or upload files to get started</p>
                    <div class="flex gap-2">
                        <button wire:click="openFolderForm" class="btn btn-secondary btn-sm"><i class="fas fa-folder-plus text-sm"></i> New Folder</button>
                        <button wire:click="openUpload" class="btn btn-primary btn-sm"><i class="fas fa-cloud-upload-alt text-sm"></i> Upload Files</button>
                    </div>
                </div>
            @endif

            {{-- Folder Form Modal --}}
            @if($showFolderForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showFolderForm', false)" x-on:keydown.escape.window="$wire.set('showFolderForm', false)">
                    <div class="modal-box w-full max-w-md mx-4">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <h3 class="font-bold text-lg">{{ $editingFolderId ? 'Rename' : 'New' }} Folder</h3>
                            <button wire:click="$set('showFolderForm', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div>
                                <label class="form-label">Folder Name</label>
                                <input type="text" wire:model="folderName" class="form-input" placeholder="Enter folder name" x-ref="folderNameInput" x-init="$nextTick(() => $refs.folderNameInput.focus())">
                                <span wire:error="folderName" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex justify-end gap-2 p-4 border-t">
                            <button wire:click="$set('showFolderForm', false)" class="btn btn-secondary">Cancel</button>
                            <button wire:click="saveFolder" class="btn btn-primary" x-bind:disabled="!$wire.folderName">
                                <i class="fas fa-check text-sm"></i> {{ $editingFolderId ? 'Rename' : 'Create' }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- File Preview Modal --}}
            @if($showPreview)
                @php $pf = $this->getPreviewFile(); @endphp
                @if($pf)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm"
                     wire:click.self="$set('showPreview', false)"
                     x-on:keydown.escape.window="$wire.set('showPreview', false)"
                     x-data="{
                         activeTab: 'preview',
                         moveFolderId: 0,
                         showMove: false
                     }">
                    <div class="relative w-full max-w-5xl mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">

                        {{-- Top Bar --}}
                        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200 {{ $pf->storage_type === 'external' ? 'bg-blue-50/80' : 'bg-gray-50/80' }}">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center
                                    {{ $pf->storage_type === 'external' ? 'bg-blue-100 text-blue-500' : ($pf->type === 'image' ? 'bg-blue-100 text-blue-500' : ($pf->type === 'video' ? 'bg-purple-100 text-purple-500' : ($pf->type === 'audio' ? 'bg-pink-100 text-pink-500' : 'bg-gray-100 text-gray-500'))) }}">
                                    @if($pf->storage_type === 'external')
                                        <i class="fab fa-google-drive"></i>
                                    @else
                                        <i class="fas {{ $pf->type === 'image' ? 'fa-file-image' : ($pf->type === 'video' ? 'fa-file-video' : ($pf->type === 'audio' ? 'fa-file-audio' : 'fa-file-alt')) }}"></i>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-bold text-gray-900 truncate">{{ $pf->name }}</h3>
                                        @if($pf->storage_type === 'external')
                                            <span class="badge badge-info text-[9px] py-0.5 px-1.5"><i class="fab fa-google-drive mr-0.5"></i> Drive</span>
                                        @endif
                                    </div>
                                    <p class="text-[11px] text-gray-400">{{ strtoupper(pathinfo($pf->name, PATHINFO_EXTENSION)) }} &middot; {{ $pf->storage_type === 'external' ? 'External Link' : $this->formatSize($pf->size) }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if($pf->storage_type === 'external')
                                    <a href="{{ $pf->external_url }}" target="_blank" rel="noopener noreferrer" class="px-3 py-1.5 rounded-lg bg-blue-500 text-white text-xs font-semibold hover:bg-blue-600 transition-colors">
                                        <i class="fas fa-external-link-alt mr-1"></i> Open in Drive
                                    </a>
                                @else
                                    <button wire:click="downloadFile({{ $pf->id }})" class="px-3 py-1.5 rounded-lg bg-[var(--brand)] text-white text-xs font-semibold hover:opacity-90 transition-opacity">
                                        <i class="fas fa-download mr-1"></i> Download
                                    </button>
                                @endif
                                <button wire:click="$set('showPreview', false)" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-200 transition-all">
                                    <i class="fas fa-times text-sm"></i>
                                </button>
                            </div>
                        </div>

                        {{-- Content --}}
                        <div class="flex flex-1 min-h-0 overflow-hidden">
                            {{-- Preview Area --}}
                            <div class="flex-1 flex items-center justify-center p-6 bg-gray-900/5 min-h-[300px]">
                                @if($pf->storage_type === 'external')
                                    {{-- External file (Google Drive link) --}}
                                    <div class="flex flex-col items-center gap-5 w-full max-w-lg">
                                        <div class="w-32 h-36 rounded-2xl bg-gradient-to-br from-blue-500 via-blue-600 to-blue-700 flex items-center justify-center shadow-xl">
                                            <div class="text-center">
                                                <i class="fab fa-google-drive text-5xl text-white mb-2 drop-shadow-lg"></i>
                                                <p class="text-[11px] font-bold text-white/90 uppercase tracking-wide">Google Drive</p>
                                            </div>
                                        </div>
                                        <p class="text-sm text-gray-500 text-center">This file is stored on Google Drive</p>

                                        {{-- Drive Link Card --}}
                                        <div class="w-full bg-gradient-to-r from-blue-50 to-blue-100/50 border-2 border-blue-200 rounded-xl p-4">
                                            <div class="flex items-center gap-3">
                                                <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-blue-500 flex items-center justify-center">
                                                    <i class="fab fa-google-drive text-white text-lg"></i>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-[10px] font-bold text-blue-400 uppercase tracking-wider mb-0.5">Drive Link</p>
                                                    <a href="{{ $pf->external_url }}" target="_blank" rel="noopener noreferrer"
                                                       class="text-sm text-blue-700 hover:text-blue-900 hover:underline break-all font-mono leading-snug">
                                                        {{ $pf->external_url }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Action Buttons --}}
                                        <div class="flex items-center gap-3">
                                            <a href="{{ $pf->external_url }}" target="_blank" rel="noopener noreferrer"
                                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-500 text-white text-sm font-bold hover:bg-blue-600 shadow-lg shadow-blue-500/25 transition-all hover:shadow-xl hover:shadow-blue-500/30">
                                                <i class="fas fa-external-link-alt"></i> Open in Google Drive
                                            </a>
                                            <button type="button"
                                                x-data="{ copied: false }"
                                                x-on:click="navigator.clipboard.writeText('{{ $pf->external_url }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white border-2 border-gray-200 text-gray-700 text-sm font-bold hover:bg-gray-50 hover:border-gray-300 transition-all">
                                                <i x-show="!copied" class="fas fa-copy"></i>
                                                <i x-show="copied" class="fas fa-check text-green-500"></i>
                                                <span x-text="copied ? 'Copied!' : 'Copy Link'"></span>
                                            </button>
                                        </div>
                                    </div>
                                @elseif($pf->type === 'image')
                                    <img src="{{ $this->getFileUrl($pf->id) }}" alt="{{ $pf->name }}" class="max-w-full max-h-[60vh] object-contain rounded-lg shadow-lg" loading="lazy">
                                @elseif($pf->type === 'video')
                                    <video src="{{ $this->getFileUrl($pf->id) }}" controls playsinline class="max-w-full max-h-[60vh] rounded-lg shadow-lg">
                                        Your browser does not support video playback.
                                    </video>
                                @elseif($pf->type === 'audio')
                                    <div class="flex flex-col items-center gap-4 w-full max-w-md">
                                        <div class="w-24 h-24 rounded-2xl bg-gradient-to-br from-pink-400 to-purple-600 flex items-center justify-center shadow-lg">
                                            <i class="fas fa-music text-3xl text-white"></i>
                                        </div>
                                        <audio src="{{ $this->getFileUrl($pf->id) }}" controls class="w-full">
                                            Your browser does not support audio playback.
                                        </audio>
                                        <p class="text-xs text-gray-500 text-center">{{ $pf->name }}</p>
                                    </div>
                                @else
                                    <div class="flex flex-col items-center gap-4">
                                        <div class="w-28 h-32 rounded-xl bg-white border-2 border-gray-200 flex items-center justify-center shadow-md">
                                            <div class="text-center">
                                                <i class="fas fa-file-alt text-4xl text-gray-300 mb-2"></i>
                                                <p class="text-[10px] font-bold text-gray-400 uppercase">{{ strtoupper(pathinfo($pf->name, PATHINFO_EXTENSION) ?: 'file') }}</p>
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-400">Preview not available for this file type</p>
                                    </div>
                                @endif
                            </div>

                            {{-- Sidebar --}}
                            <div class="w-72 border-l border-gray-200 bg-white overflow-y-auto flex-shrink-0 hidden sm:block">
                                <div class="p-4 space-y-4">
                                    {{-- Type & Size --}}
                                    <div>
                                        <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Details</h4>
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500">Storage</span>
                                                @if($pf->storage_type === 'external')
                                                    <span class="badge badge-info text-[10px]"><i class="fab fa-google-drive mr-1"></i>Google Drive</span>
                                                @else
                                                    <span class="badge badge-success text-[10px]"><i class="fas fa-hard-drive mr-1"></i>Local</span>
                                                @endif
                                            </div>
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500">Type</span>
                                                <span class="badge badge-info text-[10px]">{{ ucfirst($pf->type) }}</span>
                                            </div>
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500">Format</span>
                                                <span class="text-gray-800 font-mono text-xs">{{ strtoupper(pathinfo($pf->name, PATHINFO_EXTENSION) ?: '—') }}</span>
                                            </div>
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500">Size</span>
                                                <span class="text-gray-800 font-mono text-xs">{{ $pf->storage_type === 'external' ? 'External' : $this->formatSize($pf->size) }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    @if($pf->storage_type === 'external' && $pf->external_url)
                                        <hr class="border-gray-100">
                                        <div>
                                            <h4 class="text-[10px] font-bold text-blue-500 uppercase tracking-wider mb-2"><i class="fab fa-google-drive mr-1"></i>Google Drive Link</h4>
                                            <div class="space-y-2">
                                                <a href="{{ $pf->external_url }}" target="_blank" rel="noopener noreferrer"
                                                   class="flex items-center gap-2 p-2.5 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-xs hover:bg-blue-100 transition-colors break-all">
                                                    <i class="fab fa-google-drive flex-shrink-0 text-blue-500"></i>
                                                    <span class="flex-1 min-w-0 truncate">{{ $pf->external_url }}</span>
                                                    <i class="fas fa-external-link-alt flex-shrink-0 text-[10px] text-blue-400"></i>
                                                </a>
                                                <button type="button"
                                                    x-data="{ copied: false }"
                                                    x-on:click="navigator.clipboard.writeText('{{ $pf->external_url }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                    class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-gray-600 text-xs font-semibold hover:bg-gray-50 hover:border-gray-300 transition-all">
                                                    <i x-show="!copied" class="fas fa-copy"></i>
                                                    <i x-show="copied" class="fas fa-check text-green-500"></i>
                                                    <span x-text="copied ? 'Link Copied!' : 'Copy Drive Link'"></span>
                                                </button>
                                            </div>
                                        </div>
                                    @endif

                                    <hr class="border-gray-100">

                                    {{-- Dates --}}
                                    <div>
                                        <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Dates</h4>
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500">Uploaded</span>
                                                <span class="text-gray-800 text-xs">{{ $pf->created_at ? \App\Support\NepaliDate::display($pf->created_at) : '—' }}</span>
                                            </div>
                                            @if($pf->expiry_date)
                                                <div class="flex items-center justify-between text-sm">
                                                    <span class="text-gray-500">Expires</span>
                                                    <span class="text-xs {{ $pf->expiry_class }}">{{ $pf->expiry_label }}</span>
                                                </div>
                                                @if(!$pf->extended)
                                                    <button wire:click="extendExpiry({{ $pf->id }})" class="w-full text-center text-xs text-[var(--brand)] hover:underline py-1">
                                                        <i class="fas fa-clock mr-1"></i>Extend +5 days
                                                    </button>
                                                @else
                                                    <p class="text-center text-[10px] text-gray-400 py-1"><i class="fas fa-check-circle mr-1"></i>Already extended once</p>
                                                @endif
                                            @endif
                                        </div>
                                    </div>

                                    <hr class="border-gray-100">

                                    {{-- Tags --}}
                                    @if($pf->tags)
                                        <div>
                                            <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Tags</h4>
                                            <div class="flex flex-wrap gap-1">
                                                @foreach(explode(',', $pf->tags) as $tag)
                                                    <span class="text-[10px] bg-gray-100 text-gray-600 px-2 py-1 rounded-full">{{ trim($tag) }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    <hr class="border-gray-100">

                                    {{-- Move to Folder --}}
                                    <div>
                                        <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Move to</h4>
                                        <select x-model="moveFolderId" class="form-select text-xs py-1.5 w-full">
                                            <option value="0">Root ({{ config('app.name', 'Madhyam') }})</option>
                                            @foreach(DB::table('folders')->orderBy('name')->get() as $f)
                                                <option value="{{ $f->id }}">{{ $f->name }}</option>
                                            @endforeach
                                        </select>
                                        <button x-on:click="if(moveFolderId != {{ $pf->folder_id ?? 0 }}) { $wire.moveFileToFolder({{ $pf->id }}, parseInt(moveFolderId)); }" x-bind:disabled="moveFolderId == {{ $pf->folder_id ?? 0 }}" class="mt-2 w-full text-center text-xs font-semibold py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
                                            <i class="fas fa-arrows-alt mr-1"></i>Move
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Bottom Action Bar (mobile) --}}
                        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-200 bg-gray-50/80 sm:hidden">
                            <div class="flex gap-2">
                                @if(!$pf->extended && isset($pf->expiry_class) && $pf->expiry_class !== 'badge-danger')
                                    <button wire:click="extendExpiry({{ $pf->id }})" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-100">
                                        <i class="fas fa-clock mr-1"></i>Extend
                                    </button>
                                @endif
                            </div>
                            <button type="button" wire:click="$dispatch('open-confirm', { title: 'Delete File?', message: 'Delete &quot;{{ addslashes($pf->name) }}&quot;? This cannot be undone.', type: 'danger', action: 'deletePreviewFile', params: [{{ $pf->id }}] })" aria-label="Delete file" class="px-3 py-1.5 rounded-lg border border-red-200 text-xs text-red-600 hover:bg-red-50">
                                <i class="fas fa-trash mr-1"></i>Delete
                            </button>
                        </div>
                    </div>
                </div>
                @endif
            @endif

            {{-- Upload Modal --}}
            @if($showUpload)
                <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm"
                     wire:click.self="$set('showUpload', false)"
                     x-on:keydown.escape.window="$wire.set('showUpload', false)"
                     x-data="{
                         dragging: false,
                         upP: 0, upOn: false, upDone: false, upB: 0, upT: 0,
                         upStartTime: 0, upSpeed: 0, upEta: 0, upLastB: 0, upLastTime: 0,
                         fmt(bytes) {
                             if (!bytes) return '0 B';
                             const k = 1024, s = ['B','KB','MB','GB'];
                             const i = Math.floor(Math.log(bytes) / Math.log(k));
                             return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + s[i];
                         },
                         fmtSpeed(bps) {
                             if (bps <= 0) return '—';
                             if (bps >= 1048576) return (bps / 1048576).toFixed(1) + ' MB/s';
                             if (bps >= 1024) return (bps / 1024).toFixed(0) + ' KB/s';
                             return bps.toFixed(0) + ' B/s';
                         },
                         fmtEta(seconds) {
                             if (seconds <= 0 || !isFinite(seconds)) return '—';
                             if (seconds < 60) return '~' + Math.ceil(seconds) + 's';
                             if (seconds < 3600) return '~' + Math.floor(seconds / 60) + 'm ' + Math.ceil(seconds % 60) + 's';
                             return '~' + Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
                         },
                         calcSpeed() {
                             const now = Date.now();
                             const elapsed = (now - this.upLastTime) / 1000;
                             if (elapsed > 0.3 && this.upB > this.upLastB) {
                                 this.upSpeed = (this.upB - this.upLastB) / elapsed;
                                 const remaining = this.upT - this.upB;
                                 this.upEta = this.upSpeed > 0 ? remaining / this.upSpeed : 0;
                                 this.upLastB = this.upB;
                                 this.upLastTime = now;
                             }
                         },
                         treeExpanded: true,
                         get folderMode() { return $wire.folderMode },
                         get folderUploading() { return $wire.folderUploading },
                         get folderProgressCurrent() { return $wire.folderProgressCurrent },
                         get folderProgressTotal() { return $wire.folderProgressTotal },
                         get folderProgressFile() { return $wire.folderProgressFile },
                         get folderErrors() { return $wire.folderErrors },
                         get folderProgressPct() {
                             return this.folderProgressTotal > 0
                                 ? Math.round(this.folderProgressCurrent / this.folderProgressTotal * 100)
                                 : 0;
                         }
                     }"
                     x-on:livewire-upload-start.window="
                         if($event.target.getAttribute('wire:model') === 'pendingFiles'){
                             upOn=true;
                             upDone=false;
                             upP=0;
                             const files = $event.target.files;
                             let total = 0;
                             for (let i = 0; i < files.length; i++) {
                                 total += files[i].size;
                             }
                             upT=total;
                             upB=0;
                             upSpeed=0;
                             upEta=0;
                             upStartTime=Date.now();
                             upLastTime=Date.now();
                             upLastB=0;
                         }
                     "
                     x-on:livewire-upload-progress.window="
                         if($event.target.getAttribute('wire:model') === 'pendingFiles'){
                             upP=Math.round($event.detail.progress);
                             upB=Math.round((upP / 100) * upT);
                             calcSpeed();
                             upOn=true;
                         }
                     "
                     x-on:livewire-upload-finish.window="
                         if($event.target.getAttribute('wire:model') === 'pendingFiles'){
                             upP=100;
                             upB=upT;
                             upOn=false;
                             upDone=true;
                             upSpeed=0;
                             upEta=0;
                             setTimeout(()=>{upDone=false},5000);
                         }
                     "
                     x-on:livewire-upload-cancel.window="
                         if($event.target.getAttribute('wire:model') === 'pendingFiles'){
                             upOn=false;
                             upP=0;
                             upDone=false;
                             upSpeed=0;
                             upEta=0;
                         }
                     "
                     x-on:livewire-upload-error.window="
                         if($event.target.getAttribute('wire:model') === 'pendingFiles'){
                             upOn=false;
                             upP=0;
                             upSpeed=0;
                             upEta=0;
                         }
                     "
                >
                    <div class="modal-box w-full sm:max-w-lg sm:mx-4 max-h-[90vh] rounded-t-2xl sm:rounded-2xl flex flex-col">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b flex-shrink-0 rounded-t-2xl">
                            <h3 class="font-bold text-lg" x-text="folderUploading ? 'Uploading Folder...' : 'Upload Files'">Upload Files</h3>
                            <button wire:click="$set('showUpload', false)" class="text-gray-400 hover:text-gray-600" x-show="!folderUploading"><i class="fas fa-times"></i></button>
                        </div>

                        {{-- Storage Mode Toggle (Local / Drive / Link / Browse) --}}
                        <div class="px-4 pt-4 flex-shrink-0">
                            <div class="grid grid-cols-2 sm:grid-cols-4 bg-gray-100 rounded-lg p-1 gap-1">
                                <button wire:click="setUploadMode('local')"
                                    class="py-2 px-2 text-xs sm:text-sm font-semibold rounded-md transition-all {{ $uploadMode === 'local' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500 hover:text-gray-700' }}">
                                    <i class="fas fa-hard-drive sm:mr-1.5"></i><span class="hidden sm:inline">Local</span><span class="sm:hidden">Local</span>
                                </button>
                                @if($googleDriveConnected)
                                <button wire:click="setUploadMode('drive-upload')"
                                    class="py-2 px-2 text-xs sm:text-sm font-semibold rounded-md transition-all {{ $uploadMode === 'drive-upload' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500 hover:text-gray-700' }}">
                                    <i class="fab fa-google sm:mr-1.5"></i><span class="hidden sm:inline">Upload to Drive</span><span class="sm:hidden">Drive</span>
                                </button>
                                @endif
                                <button wire:click="setUploadMode('drive')"
                                    class="py-2 px-2 text-xs sm:text-sm font-semibold rounded-md transition-all {{ $uploadMode === 'drive' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500 hover:text-gray-700' }}">
                                    <i class="fas fa-link sm:mr-1.5"></i><span class="hidden sm:inline">Drive Link</span><span class="sm:hidden">Link</span>
                                </button>
                                @if($googleDriveConnected)
                                <button wire:click="setUploadMode('drive-browse')"
                                    class="py-2 px-2 text-xs sm:text-sm font-semibold rounded-md transition-all {{ $uploadMode === 'drive-browse' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500 hover:text-gray-700' }}">
                                    <i class="fas fa-folder-open sm:mr-1.5"></i><span class="hidden sm:inline">Browse</span><span class="sm:hidden">Browse</span>
                                </button>
                                @endif
                            </div>
                        </div>

                        <div class="p-4 space-y-4 overflow-y-auto flex-1 min-h-0">
                            @if($uploadMode === 'local' || $uploadMode === 'drive-upload')

                                {{-- Files / Folder Toggle --}}
                                @if(!$folderUploading)
                                <div class="flex items-center gap-2" x-show="!upOn">
                                    <button @click="$wire.set('folderMode', false); $wire.set('pendingFiles', [])"
                                            :class="!folderMode ? 'bg-[var(--brand)] text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                            class="flex-1 py-2 px-3 rounded-lg text-sm font-semibold transition-all flex items-center justify-center gap-1.5">
                                        <i class="fas fa-file text-xs"></i> Files
                                    </button>
                                    <button @click="$wire.set('folderMode', true); $wire.set('pendingFiles', [])"
                                            :class="folderMode ? 'bg-[var(--brand)] text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                            class="flex-1 py-2 px-3 rounded-lg text-sm font-semibold transition-all flex items-center justify-center gap-1.5">
                                        <i class="fas fa-folder-open text-xs"></i> Folder
                                    </button>
                                </div>
                                @endif

                                {{-- Folder Upload Progress --}}
                                <template x-if="folderUploading">
                                    <div class="space-y-3">
                                        <div class="bg-[rgba(var(--brand-rgb),0.04)] border border-[rgba(var(--brand-rgb),0.15)] rounded-xl p-4">
                                            <div class="flex items-center justify-between mb-2.5">
                                                <div class="flex items-center gap-2">
                                                    <div class="relative">
                                                        <i class="fas fa-cloud-upload-alt text-[var(--brand)] text-lg upload-icon-spin"></i>
                                                    </div>
                                                    <span class="text-sm font-semibold text-gray-800">Uploading folder...</span>
                                                </div>
                                                <span class="text-sm font-bold tabular-nums" :class="folderProgressPct >= 100 ? 'text-green-600' : 'text-[var(--brand)]'" x-text="folderProgressPct + '%'"></span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden mb-2.5">
                                                <div class="h-full rounded-full transition-all duration-300 ease-out"
                                                     :class="folderProgressPct >= 100 ? 'bg-green-500' : 'bg-[var(--brand)]'"
                                                     :style="'width:' + folderProgressPct + '%'"></div>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs text-gray-500 tabular-nums" x-text="folderProgressCurrent + ' / ' + folderProgressTotal + ' files'"></span>
                                                <button type="button" @click="$wire.cancelFolderUpload()" class="text-xs text-red-500 hover:text-red-700 font-medium transition-colors">
                                                    <i class="fas fa-times mr-1"></i>Cancel
                                                </button>
                                            </div>
                                        </div>

                                        {{-- Current file --}}
                                        <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2" x-show="folderProgressFile">
                                            <i class="fas fa-file text-[var(--brand)] text-xs upload-icon-spin"></i>
                                            <span class="text-xs text-gray-600 truncate" x-text="folderProgressFile"></span>
                                        </div>

                                        {{-- Errors --}}
                                        @if(!empty($folderErrors))
                                        <div class="bg-red-50 border border-red-200 rounded-lg p-3 space-y-1">
                                            <p class="text-xs font-semibold text-red-800"><i class="fas fa-exclamation-triangle mr-1"></i>Failed files:</p>
                                            @foreach($folderErrors as $error)
                                                <p class="text-[11px] text-red-600 truncate" title="{{ $error }}">{{ $error }}</p>
                                            @endforeach
                                        </div>
                                        @endif
                                    </div>
                                </template>

                                {{-- Drive Upload Progress --}}
                                @if($driveUploading)
                                    <div class="space-y-3" wire:key="drive-progress">
                                        <div class="bg-blue-50/80 border border-blue-200/60 rounded-xl p-4 shadow-sm">
                                            <div class="flex items-center justify-between mb-2.5">
                                                <div class="flex items-center gap-2">
                                                    <div class="relative">
                                                        <i class="fab fa-google-drive text-blue-500 text-lg upload-icon-spin"></i>
                                                    </div>
                                                    <span class="text-sm font-semibold text-gray-800">Uploading to Google Drive...</span>
                                                </div>
                                                <span class="text-sm font-bold tabular-nums text-blue-600" wire:key="progress-percent">{{ $driveProgressPercent }}%</span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden mb-2.5">
                                                <div class="h-full bg-gradient-to-r from-blue-500 to-blue-600 rounded-full transition-all duration-300 ease-out"
                                                     style="width: {{ $driveProgressPercent }}%"></div>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs text-gray-600 tabular-nums">
                                                    <i class="fas fa-file-alt mr-1"></i>
                                                    {{ $driveProgressCurrent }} / {{ $driveProgressTotal }} files
                                                </span>
                                                <span class="text-xs text-gray-500 flex items-center gap-1">
                                                    <i class="fas fa-hourglass-half animate-pulse"></i>
                                                    Please wait...
                                                </span>
                                            </div>
                                        </div>

                                        {{-- Current file being uploaded --}}
                                        @if($driveProgressFile)
                                        <div class="flex items-center gap-2 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2.5" wire:key="current-file-{{ $driveProgressCurrent }}">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-cloud-upload-alt text-blue-500 text-sm upload-icon-spin"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-xs text-gray-500 mb-0.5">Currently uploading:</p>
                                                <p class="text-xs font-medium text-gray-700 truncate" title="{{ $driveProgressFile }}">{{ $driveProgressFile }}</p>
                                            </div>
                                        </div>
                                        @endif
                                    </div>
                                @endif

                                {{-- Premium Upload Progress Indicator --}}
                                <div x-show="upOn && !folderUploading"
                                     x-transition:enter="transition ease-out duration-300"
                                     x-transition:enter-start="opacity-0 -translate-y-2"
                                     x-transition:enter-end="opacity-100 translate-y-0"
                                     class="space-y-3">
                                    <div class="bg-gradient-to-br from-[rgba(var(--brand-rgb),0.04)] to-[rgba(var(--brand-rgb),0.08)] border border-[rgba(var(--brand-rgb),0.15)] rounded-2xl p-5">
                                        <div class="flex items-center gap-4 mb-4">
                                            {{-- Circular Progress Ring --}}
                                            <div class="relative flex-shrink-0">
                                                <svg class="w-16 h-16 -rotate-90" viewBox="0 0 64 64">
                                                    <circle cx="32" cy="32" r="28" fill="none" stroke="#e5e7eb" stroke-width="5"></circle>
                                                    <circle cx="32" cy="32" r="28" fill="none"
                                                            stroke="var(--brand)" stroke-width="5" stroke-linecap="round"
                                                            :stroke-dasharray="2 * Math.PI * 28"
                                                            :stroke-dashoffset="2 * Math.PI * 28 * (1 - upP / 100)"
                                                            class="transition-all duration-300 ease-out"
                                                            :class="upP >= 100 ? '!stroke-green-500' : ''"></circle>
                                                </svg>
                                                <div class="absolute inset-0 flex items-center justify-center">
                                                    <span class="text-sm font-extrabold tabular-nums"
                                                          :class="upP >= 100 ? 'text-green-600' : 'text-[var(--brand)]'"
                                                          x-text="upP + '%'"></span>
                                                </div>
                                            </div>
                                            {{-- Upload Info --}}
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-bold text-gray-800 mb-0.5">Uploading files...</p>
                                                <p class="text-xs text-gray-500 tabular-nums">
                                                    <span x-text="fmt(upB)"></span> / <span x-text="fmt(upT)"></span>
                                                </p>
                                                <div class="flex items-center gap-3 mt-1.5">
                                                    <span class="inline-flex items-center gap-1 text-[11px] text-gray-500 font-medium tabular-nums">
                                                        <i class="fas fa-bolt text-amber-400 text-[9px]"></i>
                                                        <span x-text="fmtSpeed(upSpeed)"></span>
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 text-[11px] text-gray-500 font-medium tabular-nums">
                                                        <i class="fas fa-clock text-gray-400 text-[9px]"></i>
                                                        <span x-text="fmtEta(upEta)"></span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        {{-- Linear Progress Bar with Shimmer --}}
                                        <div class="w-full bg-gray-200/80 rounded-full h-2.5 overflow-hidden">
                                            <div class="h-full rounded-full transition-all duration-300 ease-out relative overflow-hidden"
                                                 :class="upP >= 100 ? 'bg-green-500' : 'bg-gradient-to-r from-[var(--brand)] via-[rgba(var(--brand-rgb),0.8)] to-[var(--brand)]'"
                                                 :style="'width:' + upP + '%'">
                                                <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/30 to-transparent upload-shimmer" x-show="upP < 100"></div>
                                            </div>
                                        </div>
                                        {{-- Cancel --}}
                                        <div class="flex items-center justify-end mt-2.5">
                                            <button type="button" @click="$wire.cancelUpload('pendingFiles')" class="text-xs text-red-500 hover:text-red-700 font-medium transition-colors flex items-center gap-1">
                                                <i class="fas fa-times"></i> Cancel Upload
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                {{-- Upload Complete Indicator (regular files) --}}
                                <div x-show="upDone && !upOn && !folderUploading" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" class="flex items-center gap-3 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-2xl px-5 py-4">
                                    <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-check text-green-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-green-800">Files ready</p>
                                        <p class="text-xs text-green-600">Click "Upload" to save to server</p>
                                    </div>
                                </div>

                                {{-- Drop Zone (hidden during upload) --}}
                                <div x-show="!upOn && !folderUploading"
                                    class="border-2 border-dashed rounded-xl p-6 sm:p-8 text-center cursor-pointer transition-colors hover:border-[var(--brand)] hover:bg-[rgba(var(--brand-rgb),0.02)]"
                                    x-on:dragover.prevent="dragging = true"
                                    x-on:dragleave="dragging = false"
                                    x-on:drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files; $refs.fileInput.dispatchEvent(new Event('change', {bubbles: true}))"
                                    x-bind:class="dragging ? 'border-[var(--brand)] bg-[rgba(var(--brand-rgb),0.05)]' : ''"
                                >
                                    @if($uploadMode === 'drive-upload')
                                        <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-3">
                                            <i class="fab fa-google text-blue-500 text-xl sm:text-2xl"></i>
                                        </div>
                                        <p class="text-sm font-semibold text-gray-700 mb-1" x-text="folderMode ? 'Upload Folder to Google Drive' : 'Upload to Google Drive'"></p>
                                        <p class="text-xs text-gray-500 mb-3" x-text="folderMode ? 'Entire folder structure will be mirrored on Drive' : 'Files go directly to your connected Drive'"></p>
                                    @else
                                        <i class="fas fa-cloud-upload-alt text-3xl sm:text-4xl text-gray-300 mb-3"></i>
                                        <p class="text-sm text-gray-600 mb-1" x-text="folderMode ? 'Select a folder to upload' : 'Drag & drop files here, or'"></p>
                                    @endif

                                    {{-- Regular file input --}}
                                    <label class="btn btn-secondary btn-sm cursor-pointer" x-show="!folderMode">
                                        <i class="fas fa-folder-open text-sm"></i> Browse Files
                                        <input type="file" wire:model="pendingFiles" x-ref="fileInput" multiple class="hidden">
                                    </label>

                                    {{-- Folder input --}}
                                    <label class="btn btn-secondary btn-sm cursor-pointer" x-show="folderMode">
                                        <i class="fas fa-folder-open text-sm"></i> Select Folder
                                        <input type="file" wire:model="pendingFiles" x-ref="folderInput" webkitdirectory multiple class="hidden">
                                    </label>

                                    <p class="text-xs text-gray-400 mt-2" x-text="folderMode ? 'All files inside the folder will be uploaded' : 'Max 200MB per file'"></p>
                                </div>

                                @error('pendingFiles') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

                                {{-- Folder Tree Preview --}}
                                @if($folderMode && count($pendingFiles) > 0 && !$folderUploading)
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm font-medium text-gray-700 flex items-center gap-1.5">
                                                <i class="fas fa-folder-open text-yellow-500"></i>
                                                <span>{{ $uploadFolderName ?: 'Selected folder' }}</span>
                                                <span class="text-xs text-gray-400 font-normal">({{ count($pendingFiles) }} files)</span>
                                            </p>
                                            <button @click="$wire.set('pendingFiles', []); $wire.set('uploadFolderName', '')" class="text-xs text-red-400 hover:text-red-600 transition-colors flex items-center gap-1">
                                                <i class="fas fa-times"></i> Clear
                                            </button>
                                        </div>
                                        <div class="bg-gray-50 rounded-xl border border-gray-200 divide-y divide-gray-100 max-h-56 overflow-y-auto">
                                            @foreach($pendingFiles as $file)
                                                @php
                                                    $relativePath = method_exists($file, 'getClientOriginalPath') ? $file->getClientOriginalPath() : $file->getClientOriginalName();
                                                    $parts = explode('/', $relativePath);
                                                    $depth = max(0, count($parts) - 2);
                                                    $fileName = end($parts);
                                                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                                                    $iconClass = match(true) {
                                                        in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'fa-file-image text-blue-500',
                                                        in_array($ext, ['mp4','mov','avi','mkv','webm']) => 'fa-file-video text-purple-500',
                                                        in_array($ext, ['mp3','wav','ogg','flac']) => 'fa-file-audio text-pink-500',
                                                        $ext === 'pdf' => 'fa-file-pdf text-red-500',
                                                        in_array($ext, ['doc','docx']) => 'fa-file-word text-blue-600',
                                                        in_array($ext, ['xls','xlsx']) => 'fa-file-excel text-green-600',
                                                        $ext === 'zip' => 'fa-file-zip text-yellow-600',
                                                        default => 'fa-file text-gray-400',
                                                    };
                                                @endphp
                                                <div class="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-100 transition-colors group" style="padding-left: {{ 12 + $depth * 16 }}px">
                                                    <i class="fas {{ $iconClass }} text-xs flex-shrink-0"></i>
                                                    <span class="text-xs text-gray-700 truncate flex-1" title="{{ $relativePath }}">{{ $fileName }}</span>
                                                    <span class="text-[10px] text-gray-400 font-mono flex-shrink-0">{{ $this->formatSize($file->getSize()) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Regular Pending Files List (non-folder mode) --}}
                                @if(!$folderMode && count($pendingFiles) > 0 && !$folderUploading)
                                    <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs font-semibold text-gray-500">{{ count($pendingFiles) }} file(s) selected</p>
                                            <template x-if="!upOn && !upDone">
                                                <button type="button" @click="$wire.set('pendingFiles', [])" class="text-xs text-red-400 hover:text-red-600 transition-colors">Clear all</button>
                                            </template>
                                        </div>
                                        @foreach($pendingFiles as $index => $file)
                                            <div class="flex items-center gap-2.5 rounded-lg bg-gray-50 hover:bg-gray-100 px-3 py-2.5 transition-colors group">
                                                @php
                                                    $ext = strtolower($file->getClientOriginalExtension());
                                                    $iconClass = match(true) {
                                                        in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'fa-file-image text-blue-500 bg-blue-50',
                                                        in_array($ext, ['mp4','mov','avi','mkv','webm']) => 'fa-file-video text-purple-500 bg-purple-50',
                                                        in_array($ext, ['mp3','wav','ogg','flac']) => 'fa-file-audio text-pink-500 bg-pink-50',
                                                        $ext === 'pdf' => 'fa-file-pdf text-red-500 bg-red-50',
                                                        in_array($ext, ['doc','docx']) => 'fa-file-word text-blue-600 bg-blue-50',
                                                        in_array($ext, ['xls','xlsx']) => 'fa-file-excel text-green-600 bg-green-50',
                                                        $ext === 'zip' => 'fa-file-zip text-yellow-600 bg-yellow-50',
                                                        default => 'fa-file text-gray-400 bg-gray-100',
                                                    };
                                                @endphp
                                                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 {{ $iconClass }}">
                                                    <i class="fas {{ explode(' ', $iconClass)[0] }} text-sm"></i>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-800 truncate">{{ $file->getClientOriginalName() }}</p>
                                                    <p class="text-[11px] text-gray-400 font-mono">{{ $this->formatSize($file->getSize()) }}</p>
                                                </div>
                                                <template x-if="!upOn">
                                                    <button wire:click="removePendingFile({{ $index }})" class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-300 hover:text-red-500 hover:bg-red-50 transition-all opacity-0 group-hover:opacity-100"><i class="fas fa-times text-xs"></i></button>
                                                </template>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @else
                                {{-- Google Drive Link --}}
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-2">
                                    <div class="flex items-start gap-2">
                                        <i class="fab fa-google-drive text-blue-500 mt-0.5"></i>
                                        <div>
                                            <p class="text-sm font-medium text-blue-800">Add a Google Drive Link</p>
                                            <p class="text-xs text-blue-600">Paste a shareable link from Google Drive. The file won't be stored locally.</p>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="form-label">Google Drive URL *</label>
                                    <input type="url" wire:model="externalUrl" class="form-input" placeholder="https://drive.google.com/file/d/...">
                                    @error('externalUrl') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="form-label">File Name *</label>
                                    <input type="text" wire:model="externalFileName" class="form-input" placeholder="e.g. Logo-Final.png">
                                    @error('externalFileName') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="form-label">File Type *</label>
                                    <select wire:model="externalFileType" class="form-select">
                                        <option value="image">Image</option>
                                        <option value="video">Video</option>
                                        <option value="audio">Audio</option>
                                        <option value="document">Document</option>
                                    </select>
                                </div>
                            @endif

                            @if($uploadMode === 'drive-browse')
                                <div x-on:drive-file-selected.window="if($event.detail.url) $wire.saveDriveFile($event.detail)">
                                    @livewire('partials.drive-browser')
                                </div>
                            @endif

                            <div x-show="!upOn && !folderUploading">
                                <label class="form-label">Tags (comma-separated)</label>
                                <input type="text" wire:model="uploadTags" class="form-input" placeholder="e.g. logo, banner, social">
                            </div>
                        </div>
                        <div class="sticky bottom-0 bg-white flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-2 p-4 border-t flex-shrink-0 rounded-b-2xl">
                            <button wire:click="$set('showUpload', false)" class="btn btn-secondary order-2 sm:order-1" x-show="!folderUploading && !$wire.driveUploading">Cancel</button>
                            @if($uploadMode === 'local')
                                {{-- Regular file upload button --}}
                                <button wire:click="uploadFiles" class="btn btn-primary order-1 sm:order-2" x-bind:disabled="$wire.pendingFiles.length === 0 || upOn || folderMode" wire:loading.attr="disabled" x-show="!folderMode">
                                    <span wire:loading.remove wire:target="uploadFiles"><i class="fas fa-upload text-sm"></i> Upload</span>
                                    <span wire:loading wire:target="uploadFiles"><i class="fas fa-spinner fa-spin text-sm"></i> Processing...</span>
                                </button>
                                {{-- Folder upload button (local) --}}
                                <button wire:click="uploadFolderToLocal" class="btn btn-primary order-1 sm:order-2" x-bind:disabled="$wire.pendingFiles.length === 0 || folderUploading" x-show="folderMode && !folderUploading">
                                    <i class="fas fa-folder-open text-sm"></i> Upload Folder
                                </button>
                            @elseif($uploadMode === 'drive-upload')
                                {{-- Regular file upload button --}}
                                <button wire:click="uploadToGoogleDrive" class="btn btn-primary order-1 sm:order-2 relative overflow-hidden" wire:loading.attr="disabled" x-bind:disabled="$wire.pendingFiles.length === 0 || upOn || folderMode || $wire.driveUploading" x-show="!folderMode && !$wire.driveUploading">
                                    <span wire:loading.remove wire:target="uploadToGoogleDrive"><i class="fab fa-google text-sm mr-1"></i> Upload to Drive</span>
                                    <span wire:loading wire:target="uploadToGoogleDrive"><i class="fas fa-spinner fa-spin mr-1"></i> Starting upload...</span>
                                </button>
                                {{-- Folder upload button (Drive) --}}
                                <button wire:click="uploadFolderToDrive" class="btn btn-primary order-1 sm:order-2" x-bind:disabled="$wire.pendingFiles.length === 0 || folderUploading" x-show="folderMode && !folderUploading">
                                    <i class="fab fa-google text-sm mr-1"></i> Upload Folder to Drive
                                </button>
                            @elseif($uploadMode === 'drive')
                                <button wire:click="saveExternalLink" class="btn btn-primary order-1 sm:order-2" x-bind:disabled="!$wire.externalUrl || !$wire.externalFileName">
                                    <i class="fas fa-link text-sm"></i> Add Link
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Google Drive Browser Modal --}}
            @if($showDriveBrowser)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" wire:click.self="$set('showDriveBrowser', false)" x-on:keydown.escape.window="$wire.set('showDriveBrowser', false)">
                    <div class="modal-box w-full max-w-3xl mx-4 max-h-[85vh] flex flex-col">
                        <div class="sticky top-0 bg-white flex items-center justify-between p-4 border-b">
                            <div class="flex items-center gap-2">
                                <i class="fab fa-google-drive text-blue-500"></i>
                                <h3 class="font-bold text-gray-900">Google Drive</h3>
                            </div>
                            <button wire:click="$set('showDriveBrowser', false)" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4 min-h-0">
                            <div x-on:drive-file-selected.window="if($event.detail.url) { $wire.saveDriveFile($event.detail); $wire.set('showDriveBrowser', false); }">
                                @livewire('partials.drive-browser')
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        blade;
    }

    public function deletePreviewFile(int $id): void
    {
        $this->deleteFile($id);
        $this->showPreview = false;
        $this->previewFileId = 0;
    }

    #[On('confirm-resolved')]
    public function onConfirmResolved(string $action, array $params = []): void
    {
        if ($action !== '' && method_exists($this, $action)) {
            $this->{$action}(...$params);
        }
    }
};
