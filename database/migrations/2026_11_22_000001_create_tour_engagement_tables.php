<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('virtual_tours', function (Blueprint $table) {
            $table->json('audio_settings')->nullable()->after('viewer_settings');
            $table->json('lead_settings')->nullable()->after('audio_settings');
        });

        Schema::create('tour_floorplans', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('virtual_tour_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tour_floorplan_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_floorplan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tour_scene_id')->constrained()->cascadeOnDelete();
            // Position as a fraction of the image (0 = left/top, 1 = right/bottom),
            // so markers stay correct at any display size.
            $table->decimal('x', 6, 5);
            $table->decimal('y', 6, 5);
            $table->timestamps();

            $table->unique(['tour_floorplan_id', 'tour_scene_id']);
        });

        Schema::create('tour_visits', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('virtual_tour_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Keyed hash of a random browser ID: never the raw ID, IP or user agent.
            $table->string('visitor_hash', 64)->index();
            $table->string('device_type', 10);
            $table->char('country', 2)->nullable();
            $table->string('referrer_host')->nullable();
            $table->boolean('is_embed')->default(false);
            $table->unsignedSmallInteger('scene_count');
            $table->unsignedSmallInteger('scenes_viewed')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');

            $table->index(['virtual_tour_id', 'started_at']);
            $table->index(['workspace_id', 'started_at']);
        });

        Schema::create('tour_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('virtual_tour_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('tour_scene_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tour_hotspot_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['virtual_tour_id', 'type', 'created_at']);
        });

        Schema::create('tour_leads', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('virtual_tour_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tour_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role', 20);
            // SHA-256 of the token sent by email; the token itself is never stored.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
        Schema::dropIfExists('tour_leads');
        Schema::dropIfExists('tour_events');
        Schema::dropIfExists('tour_visits');
        Schema::dropIfExists('tour_floorplan_markers');
        Schema::dropIfExists('tour_floorplans');

        Schema::table('virtual_tours', function (Blueprint $table) {
            $table->dropColumn(['audio_settings', 'lead_settings']);
        });
    }
};
