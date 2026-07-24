<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->string('approval_stage', 20)->default('first')->after('type');
            $table->text('rejection_reason')->nullable()->after('approval_stage');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['approval_stage', 'rejection_reason']);
        });
    }
};
