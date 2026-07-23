<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataBackupService
{
    protected array $tables = [
        'users', 'clients', 'departments', 'tasks', 'task_comments', 'workflows', 'workflow_stages',
        'contents', 'files', 'folders', 'file_expiries', 'invoices', 'invoice_payments',
        'leaves', 'salaries', 'overtime_logs', 'expenses', 'complaints', 'complaint_replies',
        'settings', 'working_hours', 'feature_access', 'data_access', 'custom_roles',
        'notifications', 'notification_rules', 'activity_logs', 'client_accounts',
        'packages', 'approvals', 'approval_comments',
    ];

    public function export(): array
    {
        $data = [];
        foreach ($this->tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $data[$table] = DB::table($table)
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->toArray();
            }
        }

        return $data;
    }

    public function import(array $payload): void
    {
        // FK checks must be disabled so we can wipe parent tables (users, clients, ...)
        // before their referencing children. Uses delete() rather than truncate() so the
        // whole restore is transactional — TRUNCATE causes an implicit commit in MySQL
        // and would defeat the rollback wrapper.
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($payload) {
                foreach ($this->tables as $table) {
                    if (! isset($payload[$table])) {
                        continue;
                    }
                    if (! DB::getSchemaBuilder()->hasTable($table)) {
                        continue;
                    }

                    DB::table($table)->delete();
                    $rows = $payload[$table];
                    if (! empty($rows)) {
                        DB::table($table)->insert($rows);
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function getTableCounts(): array
    {
        $counts = [];
        foreach ($this->tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }
}
