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
        Schema::table('site_domains', function (Blueprint $table) {
            $table->dropUnique(['verification_token']);
            $table->dropColumn('verification_token');
            $table->timestamp('ssl_enabled_at')->nullable()->after('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_domains', function (Blueprint $table) {
            $table->dropColumn('ssl_enabled_at');
            $table->string('verification_token')->nullable()->unique()->after('cloudflare_dns_record_id');
        });
    }
};
