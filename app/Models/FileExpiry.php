<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileExpiry extends Model
{
    protected $fillable = ['file_id', 'expiry_date', 'extended'];

    protected $casts = [
        'expiry_date' => 'date',
        'extended' => 'boolean',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
