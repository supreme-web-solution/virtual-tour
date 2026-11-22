<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Clip history is kept in ai_generations (one generation per clip attempt);
     * the soundtrack and narration are project-level media references.
     */
    public function up(): void
    {
        Schema::create('video_projects', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->string('public_id', 16)->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('virtual_tour_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('style', 40);
            $table->string('aspect_ratio', 8);
            $table->unsignedTinyInteger('requested_duration');
            $table->string('resolution', 10)->default('720p');
            $table->string('provider', 40);
            $table->string('model', 100)->nullable();
            $table->json('storyboard')->nullable();
            $table->string('soundtrack_mode', 20)->default('none');
            $table->foreignId('soundtrack_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignId('voiceover_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->foreignId('final_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignId('poster_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->string('visibility', 20)->default('unlisted');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('plays_count')->default(0);
            $table->unsignedInteger('completions_count')->default(0);
            $table->unsignedInteger('downloads_count')->default(0);
            $table->unsignedInteger('embed_views_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'updated_at']);
        });

        Schema::create('video_shots', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('video_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('tour_scene_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('duration');
            $table->string('camera_movement', 60);
            $table->string('scene_name', 120)->nullable();
            $table->text('prompt');
            $table->text('negative_prompt')->nullable();
            $table->string('transition', 20)->default('crossfade');
            $table->string('status', 20)->default('pending');
            $table->foreignId('generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();
            $table->foreignId('output_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['video_project_id', 'sort_order']);
        });

        Schema::create('video_exports', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('video_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('poster_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->decimal('duration', 8, 3)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('settings')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_exports');
        Schema::dropIfExists('video_shots');
        Schema::dropIfExists('video_projects');
    }
};
