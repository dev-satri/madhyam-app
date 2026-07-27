<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = [
        'category', 'description', 'amount', 'date',
        'client_id', 'paid_to', 'payment_method', 'status',
        'staff_member_id', 'location', 'item_name', 'destination', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
