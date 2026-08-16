<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->foreignId('submitted_by_client_id')
                ->nullable()
                ->after('created_by')
                ->constrained('client_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropForeign(['submitted_by_client_id']);
            $table->dropColumn('submitted_by_client_id');
        });
    }
};
