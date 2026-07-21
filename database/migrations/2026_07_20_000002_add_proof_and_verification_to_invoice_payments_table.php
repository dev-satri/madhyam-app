<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('proof_path', 500)->nullable()->after('note');
            $table->boolean('verified')->default(false)->after('proof_path');
            $table->string('verified_by')->nullable()->after('verified');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'verified', 'verified_by', 'verified_at']);
        });
    }
};
