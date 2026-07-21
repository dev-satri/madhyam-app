<?php

namespace App\Models;

use App\Models\Concerns\ScopesToClientAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Content extends Model
{
    use ScopesToClientAccount;

    protected $table = 'contents';

    protected $fillable = [
        'title', 'client_id', 'platform', 'type', 'date', 'status',
        'caption', 'hashtags', 'reference_file', 'needs_approval', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'needs_approval' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
