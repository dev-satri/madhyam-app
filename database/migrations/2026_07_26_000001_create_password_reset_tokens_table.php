<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reset-token storage for the `users` (staff) password broker.
     *
     * Schema mirrors Laravel's default Breeze migration verbatim so the
     * built-in `Illuminate\Auth\Passwords\DatabaseTokenRepository` works
     * with no configuration overrides beyond the broker table name.
     */
    public function up(): void
    {
        // Idempotent: the table pre-exists on some environments (created by an
        // older bootstrap step outside the migrations folder). Skip in that case
        // so `migrate` runs cleanly on both old and fresh databases.
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
