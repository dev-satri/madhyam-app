<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->decimal('leave_deduction', 12, 2)->default(0);
            $table->integer('paid_leaves')->default(0);
            $table->integer('unpaid_leaves')->default(0);
            $table->integer('total_work_days')->default(22);
            $table->decimal('net_salary', 12, 2)->default(0);
            $table->enum('status', ['pending', 'paid', 'approved'])->default('pending');
            $table->timestamps();

            $table->unique(['member_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salaries');
    }
};
