<?php

namespace App\Models;

use App\Services\PackageService;
use Carbon\Carbon;
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
        'package_id',
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
        'deliverables' => 'array',
        'brand_guide' => 'array',
        'social_links' => 'array',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class);
    }

    public function packageInfo(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package', 'slug');
    }

    public function linkedPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    // ── Subscription helpers ──────────────────────────────────

    public function isContractExpired(): bool
    {
        return $this->contract_end && $this->contract_end->isPast();
    }

    public function isContractExpiring(int $days = 30): bool
    {
        return $this->contract_end
            && $this->contract_end->isFuture()
            && $this->contract_end->diffInDays(Carbon::now()) <= $days;
    }

    public function daysUntilExpiry(): ?int
    {
        if (! $this->contract_end) {
            return null;
        }

        return max(0, (int) Carbon::now()->diffInDays($this->contract_end, false));
    }

    public function getExpiryStatusAttribute(): string
    {
        if (! $this->contract_end) {
            return 'no-contract';
        }
        if ($this->contract_end->isPast()) {
            return 'expired';
        }
        if ($this->contract_end->diffInDays(Carbon::now()) <= 7) {
            return 'critical';
        }
        if ($this->contract_end->diffInDays(Carbon::now()) <= 30) {
            return 'warning';
        }

        return 'active';
    }

    public function getExpiryBadgeClassAttribute(): string
    {
        return match ($this->expiry_status) {
            'expired' => 'bg-red-100 text-red-700',
            'critical' => 'bg-red-100 text-red-700',
            'warning' => 'bg-amber-100 text-amber-700',
            'active' => 'bg-green-100 text-green-700',
            'no-contract' => 'bg-gray-100 text-gray-500',
            default => 'bg-gray-100 text-gray-500',
        };
    }

    public function getExpiryLabelAttribute(): string
    {
        return match ($this->expiry_status) {
            'expired' => 'Expired',
            'critical' => $this->daysUntilExpiry() . 'd left',
            'warning' => $this->daysUntilExpiry() . 'd left',
            'active' => 'Active',
            'no-contract' => 'No Contract',
            default => 'Unknown',
        };
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
        $usage = app(PackageService::class)::getUsage($this->id);

        return round(($usage['storage_used_bytes'] ?? 0) / 1048576, 2);
    }

    public function getStorageLimitMbAttribute(): int
    {
        $limits = app(PackageService::class)::getLimits($this->id);

        return $limits['storage_limit_mb'] ?? 5120;
    }

    public function getStorageRemainingMbAttribute(): float
    {
        return max(0, $this->storage_limit_mb - $this->storage_used_mb);
    }

    public function getStoragePercentAttribute(): int
    {
        return app(PackageService::class)::getUsagePercent(
            (int) $this->storage_used_mb,
            $this->storage_limit_mb
        );
    }
}
