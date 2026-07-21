<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureAccess extends Model
{
    protected $table = 'feature_access';

    protected $fillable = ['role', 'features'];

    protected $casts = [
        'features' => 'array',
    ];
}
