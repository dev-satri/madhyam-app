<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_logs', function (Blueprint $table) {
            $table->boolean('paid')->default(false)->after('approved');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_logs', function (Blueprint $table) {
            $table->dropColumn('paid');
        });
    }
};
