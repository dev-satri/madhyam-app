<?php

namespace App\Models;

use App\Mail\PasswordResetMail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

class ClientAccount extends Authenticatable
{
    use Notifiable;

    /**
     * Virtual role for the notification and RBAC system.
     * ClientAccounts are not stored with a role column; this accessor
     * lets the notification dropdown and role-based guards treat them
     * as a first-class "client" role without a schema change on this table.
     */
    public string $role = 'client';

    protected $fillable = [
        'client_id',
        'email',
        'password',
        'name',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

    /**
     * Same override as User::sendPasswordResetNotification, but the URL is
     * tagged with `mode=client` so the reset-password page dispatches to
     * the `client_accounts` broker (separate token table, separate provider).
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', [
            'token' => $token,
            'email' => $this->email,
            'mode' => 'client',
        ]);

        Mail::to($this->email)->queue(new PasswordResetMail(
            name: $this->name,
            email: $this->email,
            resetUrl: $url,
        ));
    }
}
