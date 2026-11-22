<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cloudflare for SaaS (custom hostnames): the certificate and routing for a customer domain.
        Schema::table('workspace_domains', function (Blueprint $table) {
            $table->string('cloudflare_hostname_id', 64)->nullable()->unique()->after('last_error');
            $table->string('cloudflare_status', 40)->nullable()->after('cloudflare_hostname_id');
            $table->string('cloudflare_ssl_status', 40)->nullable()->after('cloudflare_status');
            $table->string('cloudflare_ownership_txt_name')->nullable()->after('cloudflare_ssl_status');
            $table->string('cloudflare_ownership_txt_value', 500)->nullable()->after('cloudflare_ownership_txt_name');
            $table->json('cloudflare_errors')->nullable()->after('cloudflare_ownership_txt_value');
            $table->timestamp('cloudflare_synced_at')->nullable()->after('cloudflare_errors');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_domains', function (Blueprint $table) {
            $table->dropUnique(['cloudflare_hostname_id']);
            $table->dropColumn([
                'cloudflare_hostname_id',
                'cloudflare_status',
                'cloudflare_ssl_status',
                'cloudflare_ownership_txt_name',
                'cloudflare_ownership_txt_value',
                'cloudflare_errors',
                'cloudflare_synced_at',
            ]);
        });
    }
};
