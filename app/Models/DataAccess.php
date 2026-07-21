<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataAccess extends Model
{
    protected $table = 'data_access';

    protected $fillable = ['role', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];
}
