<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Angles are stored in degrees: yaw in [-180, 180], pitch in [-90, 90].
     * Zoom is the viewer's zoom level in [0, 100] (0 = widest field of view).
     */
    public function up(): void
    {
        Schema::create('virtual_tours', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->string('public_id', 16)->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cover_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('visibility', 20)->default('unlisted');
            $table->timestamp('published_at')->nullable();
            $table->json('branding_settings')->nullable();
            $table->json('viewer_settings')->nullable();
            $table->json('map_settings')->nullable();
            $table->json('privacy_settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('tour_scenes', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('virtual_tour_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('scene_type', 30)->default('panorama');
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('initial_yaw', 7, 3)->default(0);
            $table->decimal('initial_pitch', 6, 3)->default(0);
            $table->unsignedTinyInteger('initial_zoom')->default(50);
            $table->boolean('is_visible')->default(true);
            $table->json('audio_settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['virtual_tour_id', 'sort_order']);
        });

        Schema::create('tour_hotspots', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('tour_scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_scene_id')->nullable()->constrained('tour_scenes')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('yaw', 7, 3);
            $table->decimal('pitch', 6, 3);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_hotspots');
        Schema::dropIfExists('tour_scenes');
        Schema::dropIfExists('virtual_tours');
    }
};
