<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Trash extends Model
{
    use HasFactory;

    protected $table = 'trash';

    protected $fillable = [
        'trashable_type',
        'trashable_id',
        'model_data',
        'deleted_by',
        'deleted_by_type',
    ];

    protected $casts = [
        'model_data' => 'array',
    ];

    public function trashable(): MorphTo
    {
        return $this->morphTo();
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeForModel($query, string $type)
    {
        return $query->where('trashable_type', $type);
    }

    public function scopeExpired($query)
    {
        return $query->where('created_at', '<', now()->subDays(7));
    }

    public function getDisplayLabelAttribute(): string
    {
        $model = class_basename($this->trashable_type);

        return match ($model) {
            'Task' => $this->model_data['title'] ?? "Task #{$this->trashable_id}",
            'File' => $this->model_data['name'] ?? "File #{$this->trashable_id}",
            'Folder' => $this->model_data['name'] ?? "Folder #{$this->trashable_id}",
            'Content' => $this->model_data['title'] ?? "Content #{$this->trashable_id}",
            'Workflow' => $this->model_data['title'] ?? "Workflow #{$this->trashable_id}",
            'Approval' => "Approval #{$this->trashable_id}",
            'Client' => $this->model_data['name'] ?? "Client #{$this->trashable_id}",
            'Invoice' => "Invoice #{$this->trashable_id}",
            'Complaint' => $this->model_data['subject'] ?? "Complaint #{$this->trashable_id}",
            'Leave' => "Leave #{$this->trashable_id}",
            'Expense' => "Expense #{$this->trashable_id}",
            'Salary' => "Salary #{$this->trashable_id}",
            'OvertimeLog' => "Overtime #{$this->trashable_id}",
            'TaskComment' => "Task Comment #{$this->trashable_id}",
            'ApprovalComment' => "Approval Comment #{$this->trashable_id}",
            'ComplaintReply' => "Complaint Reply #{$this->trashable_id}",
            'InvoicePayment' => "Invoice Payment #{$this->trashable_id}",
            'CustomRole' => $this->model_data['name'] ?? "Role #{$this->trashable_id}",
            default => "{$model} #{$this->trashable_id}",
        };
    }

    public function getDaysRemainingAttribute(): int
    {
        return max(0, 7 - (int) now()->diffInDays($this->created_at, false));
    }
}
