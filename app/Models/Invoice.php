<?php

namespace App\Models;

use App\Models\Concerns\ScopesToClientAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Invoice extends Model
{
    use ScopesToClientAccount;

    protected $fillable = [
        'client_id', 'amount', 'status', 'payment_status',
        'due_date', 'description', 'paid_date',
        'discount', 'discount_amount', 'installment_plan',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_date' => 'date',
        'amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'installment_plan' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function scopeOverdue($query)
    {
        return $query->whereDate('due_date', '<', Carbon::today())
            ->where('status', '!=', 'paid');
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->status === 'paid';
    }

    public function getNetAmountAttribute(): float
    {
        return (float) $this->amount - (float) $this->discount_amount;
    }

    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments->sum('amount');
    }
}
