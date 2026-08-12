<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'agency_name', 'agency_email', 'agency_phone',
        'currency', 'date_format', 'brand_color', 'logo_path', 'favicon_path', 'file_retention_days',
        'base_salary_default', 'overtime_rate_default',
        'backup_reminder_days', 'last_backup_reminder',
        'paid_leaves_per_year', 'working_days_per_month', 'daily_wage_divisor',
        'google_client_id', 'google_client_secret',
        'google_drive_session_duration',
    ];

    protected $casts = [
        'last_backup_reminder' => 'datetime',
        'base_salary_default' => 'decimal:2',
        'overtime_rate_default' => 'decimal:2',
        'paid_leaves_per_year' => 'integer',
        'working_days_per_month' => 'integer',
        'daily_wage_divisor' => 'decimal:2',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
