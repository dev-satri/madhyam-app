<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class File extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = [
        'name', 'path', 'type', 'size', 'storage_type', 'external_url',
        'folder_id', 'client_id', 'tags', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function isExternal(): bool
    {
        return $this->storage_type === 'external';
    }

    public function getUrl(): ?string
    {
        if ($this->isExternal() && $this->external_url) {
            return $this->external_url;
        }

        return $this->path ? Storage::url($this->path) : null;
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function expiry(): HasOne
    {
        return $this->hasOne(FileExpiry::class);
    }

    public function scopeExpiringSoon($query, int $days = 3)
    {
        return $query->whereHas('expiry', function ($q) use ($days) {
            $q->whereDate('expiry_date', '<=', Carbon::today()->addDays($days));
        });
    }

    public function getSizeReadableAttribute(): string
    {
        $size = $this->size;
        if ($size >= 1048576) {
            return round($size / 1048576, 2) . ' MB';
        }
        if ($size >= 1024) {
            return round($size / 1024, 2) . ' KB';
        }

        return $size . ' B';
    }
}
