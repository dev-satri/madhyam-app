<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->enum('category', ['salary', 'office', 'operations', 'software', 'equipment', 'travel', 'marketing', 'other'])->default('other');
            $table->string('description', 500);
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('date')->index();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('paid_to')->nullable();
            $table->enum('payment_method', ['cash', 'bank', 'card', 'cheque'])->default('cash');
            $table->enum('status', ['paid', 'pending'])->default('pending');
            $table->foreignId('staff_member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('location')->nullable();
            $table->string('item_name')->nullable();
            $table->string('destination')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
