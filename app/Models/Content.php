<?php

namespace App\Models;

use App\Models\Concerns\ScopesToClientAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Content extends Model
{
    use ScopesToClientAccount;

    protected $table = 'contents';

    protected $fillable = [
        'title', 'client_id', 'platform', 'type', 'date', 'due_date', 'status',
        'caption', 'hashtags', 'reference_file', 'needs_approval', 'created_by',
        'submitted_for_approval_at',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'needs_approval' => 'boolean',
        'submitted_for_approval_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
