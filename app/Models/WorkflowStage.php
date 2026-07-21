<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStage extends Model
{
    protected $fillable = ['key', 'name', 'color', 'order'];

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class, 'stage', 'key');
    }
}
