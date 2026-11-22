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
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            // Credits owned. Reserved credits are held for in-flight work and
            // are not available to spend; available = balance - reserved.
            $table->unsignedInteger('balance')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('virtual_tour_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ai_generations')->nullOnDelete();
            $table->string('generation_type', 40);
            $table->string('provider', 40);
            $table->string('model', 100)->nullable();
            $table->string('provider_request_id', 100)->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('input_payload')->nullable();
            $table->json('output_metadata')->nullable();
            $table->decimal('estimated_cost', 10, 4)->nullable();
            $table->decimal('actual_cost', 10, 4)->nullable();
            $table->unsignedInteger('credits_reserved')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['provider', 'provider_request_id']);
            $table->index('status');
        });

        Schema::create('ai_generation_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('output_type', 40);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_type', 20);
            $table->integer('amount');
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            $table->unsignedInteger('reserved_after');
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            // One reservation, one charge and one release per generation: retries
            // and duplicate webhooks can never move credits twice.
            $table->unique(['reference_type', 'reference_id', 'transaction_type'], 'credit_transactions_reference_unique');
        });

        Schema::create('provider_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_key', 191);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'event_key']);
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->foreign('generation_id')->references('id')->on('ai_generations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropForeign(['generation_id']);
        });

        Schema::dropIfExists('provider_webhook_events');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('ai_generation_outputs');
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('credit_wallets');
    }
};
