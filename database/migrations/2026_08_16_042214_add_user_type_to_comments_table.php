<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('user_type')->nullable()->after('user_id');
        });

        // Update existing comments to set user_type as User by default
        DB::table('comments')->whereNull('user_type')->update([
            'user_type' => 'App\\Models\\User',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('user_type');
        });
    }
};
