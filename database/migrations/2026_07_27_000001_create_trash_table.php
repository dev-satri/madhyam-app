<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trash', function (Blueprint $table) {
            $table->id();
            $table->string('trashable_type');
            $table->unsignedBigInteger('trashable_id');
            $table->json('model_data');
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('deleted_by_type', 10)->default('staff');
            $table->timestamps();

            $table->index(['trashable_type', 'trashable_id']);
            $table->index('deleted_by');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trash');
    }
};
