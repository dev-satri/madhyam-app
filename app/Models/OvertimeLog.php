<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeLog extends Model
{
    protected $fillable = [
        'member_id', 'date', 'hours', 'description', 'approved', 'rate',
    ];

    protected $casts = [
        'date' => 'date',
        'hours' => 'decimal:1',
        'rate' => 'decimal:2',
        'approved' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function getTotalAttribute(): float
    {
        return (float) $this->hours * (float) $this->rate;
    }
}
