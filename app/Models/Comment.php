<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'user_id',
        'user_type',
        'body',
        'attachments',
        'is_system',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_system' => 'boolean',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who made the comment (supports both User and ClientAccount)
     */
    public function getUserAttribute()
    {
        // If user_type is explicitly set, use morphTo behavior
        if (! empty($this->attributes['user_type'])) {
            $userType = $this->attributes['user_type'];
            $userId = $this->attributes['user_id'];

            if ($userType === 'App\\Models\\ClientAccount' || $userType === ClientAccount::class) {
                return ClientAccount::find($userId);
            }

            return User::find($userId);
        }

        // Fallback: try User first, then ClientAccount (for backward compatibility)
        $user = User::find($this->attributes['user_id'] ?? null);
        if ($user) {
            return $user;
        }

        return ClientAccount::find($this->attributes['user_id'] ?? null);
    }
}
