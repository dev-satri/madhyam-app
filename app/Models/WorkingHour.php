<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkingHour extends Model
{
    protected $fillable = ['day', 'start', 'end', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];
}
