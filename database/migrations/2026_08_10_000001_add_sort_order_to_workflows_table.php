<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('priority');
        });

        // Backfill sort_order based on priority and created_at within each stage
        $priorityMap = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $stages = DB::table('workflows')->distinct()->pluck('stage');

        foreach ($stages as $stage) {
            $items = DB::table('workflows')
                ->where('stage', $stage)
                ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($items as $index => $item) {
                DB::table('workflows')
                    ->where('id', $item->id)
                    ->update(['sort_order' => $index]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
