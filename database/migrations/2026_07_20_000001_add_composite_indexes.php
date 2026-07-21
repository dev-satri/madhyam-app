<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->index(['client_id', 'date'], 'contents_client_date_idx');
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->index(['assignee', 'stage'], 'workflows_assignee_stage_idx');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['assignee', 'status'], 'tasks_assignee_status_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['status', 'due_date'], 'invoices_status_due_date_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['category', 'date'], 'expenses_category_date_idx');
        });

        Schema::table('salaries', function (Blueprint $table) {
            $table->index(['month', 'year', 'status'], 'salaries_month_year_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('contents_client_date_idx');
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropIndex('workflows_assignee_stage_idx');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_assignee_status_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_status_due_date_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_category_date_idx');
        });

        Schema::table('salaries', function (Blueprint $table) {
            $table->dropIndex('salaries_month_year_status_idx');
        });
    }
};
