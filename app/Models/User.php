<?php

namespace App\Models;

use App\Mail\PasswordResetMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'department_id',
        'phone',
        'join_date',
        'status',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'join_date' => 'date',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee');
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class, 'assignee');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class, 'member_id');
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class, 'member_id');
    }

    public function overtimeLogs(): HasMany
    {
        return $this->hasMany(OvertimeLog::class, 'member_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Hide super-admin (provider) rows unless the viewer is themselves a super-admin.
     * Applies to every non-super-admin viewer, including client-guard users
     * (Auth::user() returns null on the client guard, which is treated as "not super-admin").
     */
    public function scopeVisibleTo($query, ?User $viewer = null)
    {
        $viewer = $viewer ?? Auth::user();

        if ($viewer instanceof self && $viewer->isSuperAdmin()) {
            return $query;
        }

        return $query->where('role', '!=', 'super-admin');
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

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super-admin';
    }

    public function isManagerOrAbove(): bool
    {
        return in_array($this->role, ['super-admin', 'admin', 'manager'], true);
    }

    /**
     * Route password reset links through our own Mailable so the email
     * matches the branded markdown template used by MemberWelcomeMail.
     * The `mode` query param carries the guard hint to reset-password.blade.php.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', [
            'token' => $token,
            'email' => $this->email,
            'mode' => 'staff',
        ]);

        Mail::to($this->email)->queue(new PasswordResetMail(
            name: $this->name,
            email: $this->email,
            resetUrl: $url,
        ));
    }
}
