<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Salary extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = [
        'member_id', 'month', 'year',
        'base_salary', 'overtime_pay', 'bonus',
        'leave_deduction', 'paid_leaves', 'unpaid_leaves',
        'total_work_days', 'net_salary', 'status',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'overtime_pay' => 'decimal:2',
        'bonus' => 'decimal:2',
        'leave_deduction' => 'decimal:2',
        'net_salary' => 'decimal:2',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
