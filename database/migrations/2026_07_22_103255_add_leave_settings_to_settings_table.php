<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->integer('paid_leaves_per_year')->default(12)->after('overtime_rate_default');
            $table->integer('working_days_per_month')->default(22)->after('paid_leaves_per_year');
            $table->decimal('daily_wage_divisor', 5, 2)->default(30)->after('working_days_per_month');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['paid_leaves_per_year', 'working_days_per_month', 'daily_wage_divisor']);
        });
    }
};
