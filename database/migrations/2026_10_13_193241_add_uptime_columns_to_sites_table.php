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
        Schema::table('sites', function (Blueprint $table) {
            $table->string('uptime_status', 20)->nullable()->after('error_message');
            $table->timestamp('uptime_checked_at')->nullable()->after('uptime_status');
            $table->unsignedInteger('uptime_consecutive_failures')->default(0)->after('uptime_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['uptime_status', 'uptime_checked_at', 'uptime_consecutive_failures']);
        });
    }
};
