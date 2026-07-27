<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OvertimeLog extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = [
        'member_id', 'date', 'hours', 'description', 'approved', 'rate', 'paid',
    ];

    protected $casts = [
        'date' => 'date',
        'hours' => 'decimal:1',
        'rate' => 'decimal:2',
        'approved' => 'boolean',
        'paid' => 'boolean',
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
