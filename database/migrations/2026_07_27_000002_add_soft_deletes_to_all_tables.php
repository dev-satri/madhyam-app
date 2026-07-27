<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'tasks',
            'files',
            'folders',
            'contents',
            'workflows',
            'approvals',
            'clients',
            'invoices',
            'complaints',
            'leaves',
            'expenses',
            'salaries',
            'overtime_logs',
            'task_comments',
            'approval_comments',
            'complaint_replies',
            'invoice_payments',
            'custom_roles',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'tasks',
            'files',
            'folders',
            'contents',
            'workflows',
            'approvals',
            'clients',
            'invoices',
            'complaints',
            'leaves',
            'expenses',
            'salaries',
            'overtime_logs',
            'task_comments',
            'approval_comments',
            'complaint_replies',
            'invoice_payments',
            'custom_roles',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
