<?php

namespace App\Models;

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
        return $this->exists
            && $this->access_token_expires_at
            && $this->access_token_expires_at->isFuture();
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
