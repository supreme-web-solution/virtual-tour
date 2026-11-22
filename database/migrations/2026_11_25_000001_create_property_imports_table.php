<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_imports', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 2048);
            $table->string('source_host');
            // queued → fetching → ready → importing → completed, or failed.
            $table->string('status', 20);
            $table->string('page_title')->nullable();
            $table->json('details')->nullable();
            $table->json('images')->nullable();
            $table->json('selected_images')->nullable();
            $table->unsignedSmallInteger('imported_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('rights_confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_imports');
    }
};
