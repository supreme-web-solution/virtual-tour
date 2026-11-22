<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A workspace can publish a public channel page (a gallery of its public
     * tours and videos) at /c/{channel_slug}. Off until the owner turns it on.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('channel_slug', 40)->nullable()->unique();
            $table->boolean('channel_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['channel_slug']);
            $table->dropColumn(['channel_slug', 'channel_enabled']);
        });
    }
};
