<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Task extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = [
        'title', 'type', 'client_id', 'workflow_id', 'assignee', 'due_date', 'priority',
        'status', 'description', 'description_html', 'location', 'checklist',
        'reference_file', 'submission_file', 'submission_notes', 'progress',
        'attachments',
    ];

    protected $casts = [
        'due_date' => 'date',
        'progress' => 'integer',
        'checklist' => 'array',
        'attachments' => 'array',
        'assignee' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function getAssigneeUsers()
    {
        // Handle both array and single ID cases
        if (is_array($this->assignee)) {
            $ids = $this->assignee;
        } elseif (is_numeric($this->assignee)) {
            $ids = [$this->assignee];
        } else {
            $ids = [];
        }

        return User::whereIn('id', array_filter($ids))->get();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function discussionComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
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
