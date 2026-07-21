<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'name',
        'contact',
        'email',
        'phone',
        'package',
        'amount',
        'contract_start',
        'contract_end',
        'status',
        'deliverables',
        'brand_guide',
        'social_links',
        'notes',
    ];

    protected $casts = [
        'contract_start' => 'date',
        'contract_end' => 'date',
        'amount' => 'decimal:2',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class);
    }

    public function packageInfo(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package', 'slug');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim($this->name ?? ''));
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $initials .= mb_substr($p, 0, 1);
        }

        return mb_strtoupper($initials ?: '?');
    }

    public function getStorageUsedMbAttribute(): float
    {
        $usage = app(\App\Services\PackageService::class)::getUsage($this->id);

        return round(($usage['storage_used_bytes'] ?? 0) / 1048576, 2);
    }

    public function getStorageLimitMbAttribute(): int
    {
        $limits = app(\App\Services\PackageService::class)::getLimits($this->id);

        return $limits['storage_limit_mb'] ?? 5120;
    }

    public function getStorageRemainingMbAttribute(): float
    {
        return max(0, $this->storage_limit_mb - $this->storage_used_mb);
    }

    public function getStoragePercentAttribute(): int
    {
        return app(\App\Services\PackageService::class)::getUsagePercent(
            (int) $this->storage_used_mb,
            $this->storage_limit_mb
        );
    }
}
