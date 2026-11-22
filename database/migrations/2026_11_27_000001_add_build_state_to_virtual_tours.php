<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-click tour creation: where the build is (planning, generating…)
     * and which listing import feeds it.
     */
    public function up(): void
    {
        Schema::table('virtual_tours', function (Blueprint $table) {
            $table->json('build_state')->nullable()->after('lead_settings');
        });

        Schema::table('property_imports', function (Blueprint $table) {
            $table->foreignId('virtual_tour_id')->nullable()->after('property_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('property_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('virtual_tour_id');
        });

        Schema::table('virtual_tours', function (Blueprint $table) {
            $table->dropColumn('build_state');
        });
    }
};
