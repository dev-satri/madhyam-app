<?php

namespace App\Livewire\Concerns;

use App\Models\File;
use App\Models\Folder;

trait HasPickableFiles
{
    public function getPickableFiles(?string $search = null, ?int $clientId = null, ?int $folderId = null, bool $allFiles = false): array
    {
        $q = File::with('folder:id,name')
            ->select('id', 'name', 'type', 'size', 'folder_id', 'client_id', 'storage_type', 'path', 'external_url')
            ->whereNull('deleted_at')
            ->orderBy('name');

        // Client scoping: include files belonging to this client AND shared agency files (client_id is null)
        if (!empty($clientId)) {
            $q->where(function ($sub) use ($clientId) {
                $sub->where('client_id', (int) $clientId)->orWhereNull('client_id');
            });
        }

        if ($search) {
            // When actively searching, search across all files (or within folder if explicitly inside a folder)
            $q->where('name', 'like', "%{$search}%");
            if ($folderId !== null && $folderId > 0 && !$allFiles) {
                // If the user wants to limit search to current folder
                $q->where('folder_id', $folderId);
            }
        } else {
            if ($allFiles || $folderId === -1) {
                // Return all files regardless of folder
            } elseif ($folderId !== null && $folderId > 0) {
                $q->where('folder_id', $folderId);
            } elseif ($folderId === 0) {
                // Root level files
                $q->whereNull('folder_id');
            }
        }

        return $q->get()->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->name,
            'url' => $f->getUrl(),
            'type' => $f->type,
            'size' => $f->size,
            'size_label' => $f->size_readable,
            'folder_id' => $f->folder_id,
            'folder_name' => $f->folder?->name ?? 'Root',
        ])->toArray();
    }

    public function getPickableFolders(int $parentId = 0, ?int $clientId = null): array
    {
        $q = Folder::select('id', 'name', 'parent_id', 'client_id')
            ->whereNull('deleted_at')
            ->orderBy('name');

        if ($parentId > 0) {
            $q->where('parent_id', $parentId);
        } else {
            $q->whereNull('parent_id');
        }

        if (!empty($clientId)) {
            $q->where(function ($sub) use ($clientId) {
                $sub->where('client_id', (int) $clientId)->orWhereNull('client_id');
            });
        }

        $folders = $q->get()->map(function ($folder) {
            $fileCount = File::where('folder_id', $folder->id)->whereNull('deleted_at')->count();
            $subfolderCount = Folder::where('parent_id', $folder->id)->whereNull('deleted_at')->count();

            return [
                'id' => $folder->id,
                'name' => $folder->name,
                'parent_id' => $folder->parent_id,
                'file_count' => $fileCount,
                'subfolder_count' => $subfolderCount,
            ];
        })->toArray();

        $breadcrumbs = [];
        $current = $parentId;
        while ($current > 0) {
            $folder = Folder::select('id', 'name', 'parent_id')->whereNull('deleted_at')->find($current);
            if ($folder) {
                array_unshift($breadcrumbs, ['id' => $folder->id, 'name' => $folder->name]);
                $current = $folder->parent_id ? (int) $folder->parent_id : 0;
            } else {
                break;
            }
        }

        return [
            'folders' => $folders,
            'breadcrumbs' => $breadcrumbs,
        ];
    }
}
