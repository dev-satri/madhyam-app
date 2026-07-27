<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskComment extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = ['task_id', 'user_id', 'text'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
