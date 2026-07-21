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
    }
}
