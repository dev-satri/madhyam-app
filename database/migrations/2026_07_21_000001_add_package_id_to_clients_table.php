<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('package')->constrained('packages')->nullOnDelete();
        });

        // Backfill package_id from existing slug
        $packages = DB::table('packages')->pluck('id', 'slug')->toArray();
        $clients = DB::table('clients')->select('id', 'package')->get();
        foreach ($clients as $client) {
            if (isset($packages[$client->package])) {
                DB::table('clients')->where('id', $client->id)->update(['package_id' => $packages[$client->package]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn('package_id');
        });
    }
};
