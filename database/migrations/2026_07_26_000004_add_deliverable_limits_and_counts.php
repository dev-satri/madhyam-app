<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Structured limits per deliverable type, e.g.
            //   [{"type":"reel","limit":8},{"type":"post","limit":4}]
            $table->json('deliverable_limits')->nullable()->after('deliverables');
        });

        Schema::table('package_usage', function (Blueprint $table) {
            // Per-type counters for the month, e.g.
            //   {"reel": 5, "post": 2}
            $table->json('deliverable_counts')->nullable()->after('storage_used_bytes');
        });

        // Drop the legacy free-text `deliverables` column on packages — replaced
        // by structured deliverable_limits. Per-client `clients.deliverables`
        // stays intact (that's the per-client custom copy, not the package spec).
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('deliverables');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->text('deliverables')->nullable();
        });

        Schema::table('package_usage', function (Blueprint $table) {
            $table->dropColumn('deliverable_counts');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('deliverable_limits');
        });
    }
};
