<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataBackupService
{
    protected array $tables = [
        'settings', 'departments', 'workflow_stages', 'packages',
        'users', 'clients', 'client_accounts',
        'feature_access', 'data_access', 'custom_roles',
        'contents',
        'workflows', 'tasks', 'task_comments',
        'folders', 'files', 'file_expiries',
        'invoices', 'invoice_payments',
        'leaves', 'salaries', 'overtime_logs', 'expenses',
        'complaints', 'complaint_replies',
        'approvals', 'approval_comments',
        'notifications', 'notification_rules', 'activity_logs',
        'working_hours',
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
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($payload) {
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared('PRAGMA foreign_keys = OFF');
                }

                $reversed = array_reverse($this->tables);
                foreach ($reversed as $table) {
                    if (isset($payload[$table]) && DB::getSchemaBuilder()->hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                foreach ($this->tables as $table) {
                    if (! isset($payload[$table])) {
                        continue;
                    }
                    if (! DB::getSchemaBuilder()->hasTable($table)) {
                        continue;
                    }

                    $rows = $payload[$table];
                    if (! empty($rows)) {
                        DB::table($table)->insert($rows);
                    }
                }

                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared('PRAGMA foreign_keys = ON');
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
