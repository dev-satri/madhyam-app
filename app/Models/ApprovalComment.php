<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalComment extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = ['approval_id', 'user_id', 'user_name', 'text', 'is_system'];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
