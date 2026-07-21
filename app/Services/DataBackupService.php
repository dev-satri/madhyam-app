<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DataBackupService
{
    protected array $tables = [
        'users', 'clients', 'departments', 'tasks', 'workflows', 'workflow_stages',
        'contents', 'files', 'folders', 'file_expiries', 'invoices', 'invoice_payments',
        'leaves', 'salaries', 'overtime_logs', 'expenses', 'complaints', 'complaint_replies',
        'settings', 'working_hours', 'feature_access', 'data_access', 'custom_roles',
        'notifications', 'notification_rules', 'activity_logs', 'client_accounts',
        'packages', 'approval_comments',
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
        DB::transaction(function () use ($payload) {
            foreach ($this->tables as $table) {
                if (! isset($payload[$table])) {
                    continue;
                }
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                DB::table($table)->truncate();
                $rows = $payload[$table];
                if (! empty($rows)) {
                    DB::table($table)->insert($rows);
                }
            }
        });
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
