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
            $table->unsignedTinyInteger('ssl_expiry_alert_threshold')->nullable()->after('ssl_expires_at');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->unsignedTinyInteger('expiry_alert_threshold')->nullable()->after('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_domains', function (Blueprint $table) {
            $table->dropColumn('ssl_expiry_alert_threshold');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('expiry_alert_threshold');
        });
    }
};
