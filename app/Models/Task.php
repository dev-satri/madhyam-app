<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Task extends Model
{
    protected $fillable = [
        'title', 'type', 'client_id', 'assignee', 'due_date', 'priority',
        'status', 'description', 'location', 'checklist',
        'reference_file', 'submission_file', 'submission_notes', 'progress',
    ];

    protected $casts = [
        'due_date' => 'date',
        'progress' => 'integer',
        'checklist' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::today())
            ->where('status', '!=', 'completed');
    }

    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() && $this->status !== 'completed';
    }
}
