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
            $table->timestamp('ssl_expires_at')->nullable()->after('ssl_enabled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_domains', function (Blueprint $table) {
            $table->dropColumn('ssl_expires_at');
        });
    }
};
