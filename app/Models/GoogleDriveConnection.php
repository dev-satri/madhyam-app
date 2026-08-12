<?php

namespace App\Models;

use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Model;

class GoogleDriveConnection extends Model
{
    protected $fillable = [
        'google_email',
        'refresh_token_encrypted',
        'access_token_encrypted',
        'access_token_expires_at',
        'scopes',
        'root_folder_id',
        'connected_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'access_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'refresh_token_encrypted' => 'encrypted',
            'access_token_encrypted' => 'encrypted',
        ];
    }

    public function connectedBy()
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function isActive(): bool
    {
        if (! $this->exists || ! $this->refresh_token_encrypted) {
            return false;
        }

        // Access token still valid
        if ($this->access_token_expires_at && $this->access_token_expires_at->isFuture()) {
            return true;
        }

        // Access token expired — check session duration using refresh token
        $duration = Setting::current()->google_drive_session_duration ?? '5d';

        if ($duration === 'forever') {
            $this->refreshToken();

            return true;
        }

        $hours = match ($duration) {
            '24h' => 24,
            '5d' => 120,
            default => 120,
        };

        if (! $this->created_at->copy()->addHours($hours)->isFuture()) {
            return false;
        }

        $this->refreshToken();

        return true;
    }

    private function refreshToken(): void
    {
        try {
            app(GoogleDriveService::class)->refreshAccessToken($this);
        } catch (\Exception $e) {
            report($e);
        }
    }

    public static function getActive(): ?static
    {
        return static::firstWhere('singleton', 'org');
    }

    public static function store(array $data): static
    {
        return static::updateOrCreate(
            ['singleton' => 'org'],
            $data
        );
    }

    public static function disconnect(): bool
    {
        return static::where('singleton', 'org')->delete() > 0;
    }
}
