<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['text', 'type', 'read', 'link', 'for_role'];

    protected $casts = [
        'read' => 'boolean',
    ];
}
