<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use App\Models\Concerns\ScopesToClientAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Complaint extends Model
{
    use InteractsWithTrash, ScopesToClientAccount, SoftDeletes;

    protected $fillable = [
        'client_id', 'title', 'description',
        'status', 'assigned_to', 'priority',
        'resolution_notes', 'status_notes',
    ];

    protected $casts = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ComplaintReply::class);
    }
}
