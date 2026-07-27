<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Phase 0: Independent config/lookup tables
        $this->call([
            SettingsSeeder::class,
            WorkingHoursSeeder::class,
            DepartmentSeeder::class,
            PackageSeeder::class,
            WorkflowStageSeeder::class,
            NotificationRuleSeeder::class,
        ]);

        // Phase 1: Users (depends on departments)
        $this->call([
            DemoAccountsSeeder::class,
        ]);

        // Phase 2: Clients (depends on packages)
        $this->call([
            ClientSeeder::class,
        ]);

        // Phase 3: Client portal accounts (depends on clients)
        $this->call([
            ClientAccountSeeder::class,
        ]);

        // Phase 4: Content modules (depend on clients + users)
        $this->call([
            ContentSeeder::class,
            WorkflowSeeder::class,
            TaskSeeder::class,
            TaskCommentSeeder::class,
            ApprovalSeeder::class,
            ApprovalCommentSeeder::class,
        ]);

        // Phase 5: File management (depends on clients + users)
        $this->call([
            FolderSeeder::class,
            FileSeeder::class,
            FileExpirySeeder::class,
        ]);

        // Phase 6: Finance (depends on clients + users)
        $this->call([
            InvoiceSeeder::class,
            InvoicePaymentSeeder::class,
            ExpenseSeeder::class,
            SalarySeeder::class,
            OvertimeLogSeeder::class,
            LeaveSeeder::class,
        ]);

        // Phase 7: RBAC + Complaints + Notifications + Logs
        $this->call([
            FeatureAccessSeeder::class,
            DataAccessSeeder::class,
            CustomRoleSeeder::class,
            ComplaintSeeder::class,
            ComplaintReplySeeder::class,
            NotificationSeeder::class,
            PackageUsageSeeder::class,
            ActivityLogSeeder::class,
        ]);

        $this->printFinalTestCredentials();
    }

    /**
     * Print the 6 test credentials to the console after seeding so the
     * user sees them at the end of `php artisan migrate:fresh --seed`.
     * Full test plan lives at docs/FINAL_TEST_PLAN.md.
     */
    private function printFinalTestCredentials(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->line('');
        $this->command->line('<fg=cyan>═══════════════ MADHYAM — FINAL TEST CREDENTIALS ═══════════════</>');
        $this->command->line('<fg=yellow>Staff login (auth:web)  →  http://localhost/login</>');
        $this->command->line('  super-admin    superadmin@madhyam.com       SuperAdmin@123');
        $this->command->line('  admin          admin@madhyam.com            Admin@123');
        $this->command->line('  editor         staff.editor@madhyam.com     Staff@123');
        $this->command->line('  videographer   staff.video@madhyam.com      Staff@123');
        $this->command->line('');
        $this->command->line('<fg=yellow>Client portal (auth:client)  →  http://localhost/client/login</>');
        $this->command->line('  Himalayan Coffee Co.       client1@madhyam.com       Client@123');
        $this->command->line('  Trek Nepal Adventures      client2@madhyam.com       Client@123');
        $this->command->line('');
        $this->command->line('<fg=gray>See docs/FINAL_TEST_PLAN.md for use cases + testing tickets per role.</>');
        $this->command->line('<fg=cyan>═══════════════════════════════════════════════════════════════</>');
    }
}
