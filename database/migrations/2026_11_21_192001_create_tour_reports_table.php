<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports from visitors about a public tour ("Report this tour").
     */
    public function up(): void
    {
        Schema::create('tour_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('virtual_tour_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 40);
            $table->text('details')->nullable();
            // A keyed hash of the reporter's IP, only to stop repeat reports; never the address itself.
            $table->string('reporter_hash', 64)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['virtual_tour_id', 'reporter_hash']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_reports');
    }
};
