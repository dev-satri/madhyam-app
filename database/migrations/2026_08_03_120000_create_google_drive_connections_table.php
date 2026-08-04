<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single row holds the org's Google Drive OAuth connection.
     * The `singleton` unique column enforces one-row-only so two
     * admins racing a Connect flow cannot create orphan rows.
     * Refresh/access tokens are stored encrypted via the model's
     * `encrypted` cast (transparent to callers, keyed by APP_KEY).
     */
    public function up(): void
    {
        Schema::create('google_drive_connections', function (Blueprint $table) {
            $table->id();
            $table->string('singleton', 16)->unique()->default('org');
            $table->string('google_email');
            $table->text('refresh_token_encrypted');
            $table->text('access_token_encrypted')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('root_folder_id', 120)->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive_connections');
    }
};
