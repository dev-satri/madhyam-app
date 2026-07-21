<?php

namespace App\Models;

use App\Models\Concerns\ScopesToClientAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Workflow extends Model
{
    use ScopesToClientAccount;

    protected $fillable = [
        'title', 'client_id', 'type', 'stage', 'deadline', 'assignee',
        'priority', 'notes', 'tags', 'status', 'submitted_by',
    ];

    protected $casts = [
        'deadline' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
