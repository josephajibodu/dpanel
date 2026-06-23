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
            $table->string('verification_token')->nullable()->unique()->after('cloudflare_dns_record_id');
            $table->timestamp('verified_at')->nullable()->after('verification_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_domains', function (Blueprint $table) {
            $table->dropColumn(['verification_token', 'verified_at']);
        });
    }
};
