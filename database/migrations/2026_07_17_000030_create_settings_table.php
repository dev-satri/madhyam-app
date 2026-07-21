<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('agency_name')->default('Madhyam');
            $table->string('agency_email')->nullable();
            $table->string('agency_phone', 50)->nullable();
            $table->enum('currency', ['NPR', 'INR', 'USD'])->default('NPR');
            $table->string('brand_color', 7)->default('#4f46e5');
            $table->integer('file_retention_days')->default(5);
            $table->decimal('base_salary_default', 12, 2)->default(25000);
            $table->decimal('overtime_rate_default', 8, 2)->default(500);
            $table->integer('backup_reminder_days')->default(7);
            $table->timestamp('last_backup_reminder')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
