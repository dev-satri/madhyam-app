<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['text', 'type', 'read', 'link', 'for_role', 'client_id'];

    protected $casts = [
        'read' => 'boolean',
        'client_id' => 'integer',
    ];
}
