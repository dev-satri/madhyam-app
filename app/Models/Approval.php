<?php

namespace App\Models;

use App\Models\Concerns\ScopesToClientAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Approval extends Model
{
    use ScopesToClientAccount;

    protected $fillable = [
        'title', 'client_id', 'content_id', 'type', 'status', 'approval_stage',
        'submitted_by', 'notes', 'reference_file', 'rejection_reason',
    ];

    protected $casts = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ApprovalComment::class);
    }
}
