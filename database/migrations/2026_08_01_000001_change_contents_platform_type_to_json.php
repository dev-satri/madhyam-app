<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->json('platform_new')->nullable()->after('platform');
            $table->json('type_new')->nullable()->after('type');
        });

        DB::statement("UPDATE contents SET platform_new = JSON_ARRAY(platform), type_new = JSON_ARRAY(type)");

        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['platform', 'type']);
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->renameColumn('platform_new', 'platform');
            $table->renameColumn('type_new', 'type');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('platform_old', 50)->nullable()->after('platform');
            $table->string('type_old', 50)->nullable()->after('type');
        });

        DB::statement("UPDATE contents SET platform_old = JSON_UNQUOTE(JSON_EXTRACT(platform, '$[0]')), type_old = JSON_UNQUOTE(JSON_EXTRACT(type, '$[0]'))");

        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['platform', 'type']);
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->renameColumn('platform_old', 'platform');
            $table->renameColumn('type_old', 'type');
        });
    }
};
