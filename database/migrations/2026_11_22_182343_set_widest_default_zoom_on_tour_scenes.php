<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scenes open zoomed out (0 = widest field of view) so visitors see as
     * much of the space as possible. Scenes still on the old default of 50
     * are moved too; any other value was chosen by the author and is kept.
     */
    public function up(): void
    {
        Schema::table('tour_scenes', function (Blueprint $table) {
            $table->unsignedTinyInteger('initial_zoom')->default(0)->change();
        });

        DB::table('tour_scenes')->where('initial_zoom', 50)->update(['initial_zoom' => 0]);
    }

    public function down(): void
    {
        Schema::table('tour_scenes', function (Blueprint $table) {
            $table->unsignedTinyInteger('initial_zoom')->default(50)->change();
        });
    }
};
