<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    protected $fillable = [
        'member_id', 'type', 'start_date', 'end_date',
        'reason', 'status', 'approved_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getDaysAttribute(): int
    {
        return $this->start_date && $this->end_date
            ? (int) $this->start_date->diffInDays($this->end_date) + 1
            : 0;
    }

    public function isPaid(): bool
    {
        return in_array($this->type, ['annual', 'casual'], true);
    }
}
