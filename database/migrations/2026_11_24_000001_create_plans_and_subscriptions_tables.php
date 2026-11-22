<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plans are configured by platform admins. A workspace without a current
     * subscription uses the default plan. Each billing period is its own row
     * so monthly credits are granted exactly once per period.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('price_monthly')->nullable()->comment('Minor units; null = contact us');
            $table->char('currency', 3)->default('USD');
            $table->json('entitlements');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->string('provider', 30)->default('manual');
            $table->string('provider_reference')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('credits_granted')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['subscription_id', 'starts_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('current_workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });

        Schema::dropIfExists('subscription_periods');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
