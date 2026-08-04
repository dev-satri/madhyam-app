<?php

namespace App\Livewire\Partials;

use App\Models\GoogleDriveConnection;
use App\Services\GoogleDriveService;
use Livewire\Component;

class DriveBrowser extends Component
{
    public string $search = '';

    public ?string $currentFolderId = null;

    public array $folderPath = [];

    public bool $isConnected = false;

    public bool $isLoading = false;

    public array $files = [];

    public ?string $selectedFileId = null;

    public ?array $selectedFile = null;

    public string $newFolderName = '';

    public bool $showNewFolderForm = false;

    public ?string $renamingFileId = null;

    public string $renamingName = '';

    public ?string $movingFileId = null;

    public ?string $movingFileName = null;

    public bool $showMoveModal = false;

    public function boot(): void
    {
        $this->loadConnectionStatus();
    }

    public function loadConnectionStatus(): void
    {
        $connection = GoogleDriveConnection::getActive();
        $this->isConnected = $connection && $connection->isActive();
    }

    public function mount(): void
    {
        if ($this->isConnected) {
            $this->loadFiles();
        }
    }

    public function loadFiles(): void
    {
        $this->isLoading = true;

        try {
            $result = app(GoogleDriveService::class)->listFiles($this->currentFolderId);
            $this->files = $result['files'] ?? [];
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Failed to load files: ' . $e->getMessage(), type: 'error');
        } finally {
            $this->isLoading = false;
        }
    }

    public function openFolder(string $folderId, string $folderName): void
    {
        $this->currentFolderId = $folderId;
        $this->folderPath[] = ['id' => $folderId, 'name' => $folderName];
        $this->loadFiles();
    }

    public function navigateToFolder(int $index): void
    {
        if ($index === -1) {
            $this->currentFolderId = null;
            $this->folderPath = [];
        } else {
            $this->currentFolderId = $this->folderPath[$index]['id'];
            $this->folderPath = array_slice($this->folderPath, 0, $index + 1);
        }
        $this->loadFiles();
    }

    public function selectFile(array $file): void
    {
        $this->selectedFileId = $file['id'];
        $this->selectedFile = $file;
    }

    public function confirmSelection(): void
    {
        if (! $this->selectedFile) {
            return;
        }

        $attachment = GoogleDriveService::attachmentFromDrive($this->selectedFile);

        $this->dispatch('drive-file-selected', url: $attachment['url'] ?? '', name: $attachment['name'] ?? '', drive_file_id: $attachment['drive_file_id'] ?? null, mime: $attachment['mime'] ?? null, size: $attachment['size'] ?? null, thumbnail: $attachment['thumbnail'] ?? null);

        $this->selectedFileId = null;
        $this->selectedFile = null;
    }

    public function resetSelection(): void
    {
        $this->selectedFileId = null;
        $this->selectedFile = null;
    }

    public function createFolder(): void
    {
        $this->validate(['newFolderName' => 'required|string|max:255']);

        try {
            app(GoogleDriveService::class)->createFolder($this->newFolderName, $this->currentFolderId);
            $this->newFolderName = '';
            $this->showNewFolderForm = false;
            $this->loadFiles();
            $this->dispatch('toast', message: 'Folder created', type: 'success');
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Failed to create folder: ' . $e->getMessage(), type: 'error');
        }
    }

    public function startRename(array $file): void
    {
        $this->renamingFileId = $file['id'];
        $this->renamingName = $file['name'];
    }

    public function saveRename(): void
    {
        $this->validate(['renamingName' => 'required|string|max:255']);

        try {
            app(GoogleDriveService::class)->renameFile($this->renamingFileId, $this->renamingName);
            $this->renamingFileId = null;
            $this->renamingName = '';
            $this->loadFiles();
            $this->dispatch('toast', message: 'File renamed', type: 'success');
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Failed to rename: ' . $e->getMessage(), type: 'error');
        }
    }

    public function cancelRename(): void
    {
        $this->renamingFileId = null;
        $this->renamingName = '';
    }

    public function startMove(array $file): void
    {
        $this->movingFileId = $file['id'];
        $this->movingFileName = $file['name'];
        $this->showMoveModal = true;
    }

    public function moveToFolder(string $folderId): void
    {
        try {
            $service = app(GoogleDriveService::class);
            $file = $service->getFile($this->movingFileId);
            $oldParentId = $file['parents'][0] ?? 'root';

            $service->moveFile($this->movingFileId, $folderId, $oldParentId);

            $this->showMoveModal = false;
            $this->movingFileId = null;
            $this->movingFileName = null;
            $this->loadFiles();
            $this->dispatch('toast', message: 'File moved', type: 'success');
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Failed to move: ' . $e->getMessage(), type: 'error');
        }
    }

    public function deleteFile(array $file): void
    {
        try {
            app(GoogleDriveService::class)->deleteFile($file['id']);
            $this->loadFiles();
            $this->dispatch('toast', message: 'File deleted', type: 'success');
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Failed to delete: ' . $e->getMessage(), type: 'error');
        }
    }

    public function performSearch(): void
    {
        $this->isLoading = true;

        try {
            $result = app(GoogleDriveService::class)->searchFiles($this->search);
            $this->files = $result['files'] ?? [];
            $this->currentFolderId = null;
            $this->folderPath = [];
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Search failed: ' . $e->getMessage(), type: 'error');
        } finally {
            $this->isLoading = false;
        }
    }

    public function updatedSearch(): void
    {
        if (strlen($this->search) >= 2) {
            $this->performSearch();
        } elseif ($this->search === '') {
            $this->loadFiles();
        }
    }

    public function render()
    {
        return view('livewire.partials.drive-browser');
    }
}
