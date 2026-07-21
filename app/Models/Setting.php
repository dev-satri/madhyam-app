<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'agency_name', 'agency_email', 'agency_phone',
        'currency', 'brand_color', 'file_retention_days',
        'base_salary_default', 'overtime_rate_default',
        'backup_reminder_days', 'last_backup_reminder',
    ];

    protected $casts = [
        'last_backup_reminder' => 'datetime',
        'base_salary_default' => 'decimal:2',
        'overtime_rate_default' => 'decimal:2',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
