<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('platform', 50);
            $table->string('type', 50);
            $table->date('date')->index();
            $table->string('status', 50)->default('draft');
            $table->text('caption')->nullable();
            $table->text('hashtags')->nullable();
            $table->string('reference_file')->nullable();
            $table->boolean('needs_approval')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
