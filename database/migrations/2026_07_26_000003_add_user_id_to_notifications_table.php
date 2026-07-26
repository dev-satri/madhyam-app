<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add per-user targeting to notifications.
     *
     * The original schema targets by `for_role` only — role-broadcast rows are
     * still valid (approvals, workflow, contract expiry all rely on that),
     * but Laravel `Notification` classes need to record which specific user
     * received a row so the bell dropdown can scope by `user_id` for
     * assignments, deadline reminders, and comment mentions.
     */
    public function up(): void
    {
        if (Schema::hasColumn('notifications', 'user_id')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('client_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
