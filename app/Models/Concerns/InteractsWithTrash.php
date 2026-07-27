<?php

namespace App\Models\Concerns;

use App\Models\Trash;
use Illuminate\Support\Facades\Auth;

trait InteractsWithTrash
{
    /**
     * Override delete to move to trash instead of hard deleting.
     */
    public function delete(): bool
    {
        if ($this->trashed()) {
            return false;
        }

        $snapshot = $this->toArray();

        Trash::create([
            'trashable_type' => static::class,
            'trashable_id' => $this->getKey(),
            'model_data' => $snapshot,
            'deleted_by' => Auth::id(),
            'deleted_by_type' => Auth::guard('client')->check() ? 'client' : 'staff',
        ]);

        return parent::delete();
    }

    /**
     * Restore a model from trash by trash record ID.
     */
    public static function restoreFromTrash(int $trashId): bool
    {
        $trash = Trash::findOrFail($trashId);

        $model = static::withTrashed()->find($trash->trashable_id);

        if (! $model) {
            $trash->delete();

            return false;
        }

        $model->restore();
        $trash->delete();

        return true;
    }

    /**
     * Force delete a trashed model permanently.
     */
    public static function forceDeleteFromTrash(int $trashId): bool
    {
        $trash = Trash::findOrFail($trashId);

        $model = static::withTrashed()->find($trash->trashable_id);

        if ($model) {
            $model->forceDelete();
        }

        $trash->delete();

        return true;
    }

    /**
     * Purge all expired trash items (older than 7 days).
     */
    public static function purgeExpired(): int
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
}
