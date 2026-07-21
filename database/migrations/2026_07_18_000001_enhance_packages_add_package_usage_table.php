<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->integer('content_limit')->default(30)->after('features');
            $table->integer('workflow_limit')->default(20)->after('content_limit');
            $table->integer('storage_limit_mb')->default(1024)->after('workflow_limit');
            $table->integer('revision_limit')->default(3)->after('storage_limit_mb');
            $table->boolean('priority_support')->default(false)->after('revision_limit');
            $table->json('included_platforms')->nullable()->after('priority_support');
        });

        Schema::create('package_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('content_created')->default(0);
            $table->unsignedInteger('content_published')->default(0);
            $table->unsignedInteger('workflow_items')->default(0);
            $table->unsignedInteger('approvals_used')->default(0);
            $table->unsignedInteger('files_uploaded')->default(0);
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
            $table->timestamps();

            $table->unique(['client_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_usage');
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn([
                'content_limit', 'workflow_limit', 'storage_limit_mb',
                'revision_limit', 'priority_support', 'included_platforms',
            ]);
        });
    }
};
