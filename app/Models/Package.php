<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'monthly_amount',
        'deliverables',
        'features',
        'status',
        'content_limit',
        'workflow_limit',
        'storage_limit_mb',
        'revision_limit',
        'priority_support',
        'included_platforms',
    ];

    protected $casts = [
        'features' => 'array',
        'included_platforms' => 'array',
        'deliverables' => 'array',
        'monthly_amount' => 'decimal:2',
        'priority_support' => 'boolean',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'package', 'slug');
    }
}
