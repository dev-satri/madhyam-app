<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'user', 'text', 'time'];

    protected $casts = [
        'time' => 'datetime',
    ];

    public function userModel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
