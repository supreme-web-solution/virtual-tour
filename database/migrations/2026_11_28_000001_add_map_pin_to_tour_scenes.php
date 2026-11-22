<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each scene is on the map (WGS84 degrees), for map pins that
     * visitors use to move between scenes.
     */
    public function up(): void
    {
        Schema::table('tour_scenes', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('initial_zoom');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('tour_scenes', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
