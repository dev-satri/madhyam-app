<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Config / lookup tables
        $this->call([
            SettingsSeeder::class,
            WorkingHoursSeeder::class,
            DepartmentSeeder::class,
            PackageSeeder::class,
            WorkflowStageSeeder::class,
            NotificationRuleSeeder::class,
        ]);

        // Users (superadmin + admin only)
        $this->call([
            DemoAccountsSeeder::class,
        ]);

        // RBAC & permissions
        $this->call([
            FeatureAccessSeeder::class,
            DataAccessSeeder::class,
            CustomRoleSeeder::class,
        ]);

        $this->printCredentials();
    }

    private function printCredentials(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->line('');
        $this->command->line('<fg=cyan>MADHYAM — Login Credentials</>');
        $this->command->line('<fg=yellow>http://localhost/login</>');
        $this->command->line('  superadmin@madhyam.com    SuperAdmin@123');
        $this->command->line('  admin@madhyam.com         Admin@123');
        $this->command->line('');
    }
}
