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
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('asset_type', 20);
            $table->string('source_type', 20)->default('upload');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('format', 20)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration', 10, 3)->nullable();
            $table->string('cloudinary_public_id')->unique();
            $table->string('cloudinary_resource_type', 10);
            $table->text('secure_url');
            $table->text('thumbnail_url')->nullable();
            $table->string('provider', 40)->nullable();
            $table->unsignedBigInteger('generation_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'asset_type']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreign('cover_asset_id')->references('id')->on('media_assets')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['cover_asset_id']);
        });

        Schema::dropIfExists('media_assets');
    }
};
