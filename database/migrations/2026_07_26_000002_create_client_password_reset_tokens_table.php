<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reset-token storage for the `client_accounts` password broker.
     *
     * Separate from `password_reset_tokens` on purpose — email is the primary
     * key there, and an email that happens to exist in both `users` and
     * `client_accounts` would otherwise overwrite tokens across guards and
     * let a staff reset link redeem a client account (or vice versa).
     */
    public function up(): void
    {
        if (Schema::hasTable('client_password_reset_tokens')) {
            return;
        }

        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_password_reset_tokens');
    }
};
