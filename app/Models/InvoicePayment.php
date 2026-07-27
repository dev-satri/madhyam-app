<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoicePayment extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = ['invoice_id', 'amount', 'date', 'method', 'note', 'proof_path', 'verified', 'verified_by', 'verified_at'];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
