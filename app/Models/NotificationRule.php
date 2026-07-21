<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    protected $fillable = ['name', 'trigger', 'days', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'days' => 'integer',
    ];
}
