<?php

namespace App\Services;

use App\Models\Trash;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TrashService
{
    /**
     * Get paginated trashed items with optional type filter.
     */
    public function getTrashedItems(?string $type = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Trash::with('dealer')->latest();

        if ($type) {
            $query->forModel($type);
        }

        return $query->paginate($perPage);
    }

    /**
     * Restore a model from trash.
     */
    public function restore(int $trashId): bool
    {
        $trash = Trash::findOrFail($trashId);
        $modelClass = $trash->trashable_type;

        if (! class_exists($modelClass)) {
            return false;
        }

        return $modelClass::restoreFromTrash($trashId);
    }

    /**
     * Force delete a trashed model permanently.
     */
    public function forceDelete(int $trashId): bool
    {
        $trash = Trash::findOrFail($trashId);
        $modelClass = $trash->trashable_type;

        if (! class_exists($modelClass)) {
            $trash->delete();

            return false;
        }

        return $modelClass::forceDeleteFromTrash($trashId);
    }

    /**
     * Purge all expired trash items (older than 7 days).
     */
    public function purgeExpired(): int
    {
        $expired = Trash::expired()->get();
        $count = 0;

        foreach ($expired as $item) {
            $modelClass = $item->trashable_type;

            if (class_exists($modelClass)) {
                $model = $modelClass::withTrashed()->find($item->trashable_id);

                if ($model) {
                    $model->forceDelete();
                }
            }

            $item->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Get trash counts grouped by model type.
     */
    public function getTrashCounts(): array
    {
        return Trash::selectRaw('trashable_type, count(*) as count')
            ->groupBy('trashable_type')
            ->get()
            ->map(fn ($row) => [
                'type' => class_basename($row->trashable_type),
                'full_type' => $row->trashable_type,
                'count' => $row->count,
            ])
            ->toArray();
    }

    /**
     * Get total trash count.
     */
    public function getTotalCount(): int
    {
        return Trash::count();
    }
}
