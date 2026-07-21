<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('settings')->insert([
            'agency_name' => 'Madhyam Agency',
            'agency_email' => 'admin@madhyam.com',
            'agency_phone' => '+977-9800000000',
            'currency' => 'NPR',
            'brand_color' => '#4f46e5',
            'file_retention_days' => 5,
            'base_salary_default' => 25000,
            'overtime_rate_default' => 500,
            'backup_reminder_days' => 7,
            'last_backup_reminder' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
