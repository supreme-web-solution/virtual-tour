<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A custom domain that serves a workspace's public tours and videos.
        Schema::create('workspace_domains', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('host')->unique();
            // pending → verified, or failed (DNS missing or removed later).
            $table->string('status', 20)->default('pending');
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'last_checked_at']);
        });

        // Workspace-wide brand used on public pages (white-label).
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('branding')->nullable()->after('is_personal');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('branding');
        });

        Schema::dropIfExists('workspace_domains');
    }
};
