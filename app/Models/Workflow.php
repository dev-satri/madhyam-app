<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use App\Models\Concerns\ScopesToClientAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Workflow extends Model
{
    use InteractsWithTrash, ScopesToClientAccount, SoftDeletes;

    protected $fillable = [
        'title', 'client_id', 'content_id', 'type', 'stage', 'deadline', 'assignee',
        'priority', 'notes', 'description_html', 'tags', 'status', 'submitted_by', 'revision_notes',
        'attachments',
    ];

    protected $casts = [
        'deadline' => 'date',
        'attachments' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function stageInfo(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'stage', 'key');
    }

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function discussionComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('deadline')
            ->whereDate('deadline', '<', Carbon::today())
            ->where('stage', '!=', 'published');
    }

    public function isOverdue(): bool
    {
        return $this->deadline && $this->deadline->isPast() && $this->stage !== 'published';
    }
}
