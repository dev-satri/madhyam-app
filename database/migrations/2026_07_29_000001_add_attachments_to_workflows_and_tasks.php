<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('notes');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('reference_file');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
