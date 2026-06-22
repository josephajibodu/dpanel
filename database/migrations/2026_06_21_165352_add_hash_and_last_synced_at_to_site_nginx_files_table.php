<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_nginx_files', function (Blueprint $table) {
            $table->string('hash', 255)->nullable()->after('content');
            $table->timestamp('last_synced_at')->nullable()->after('sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('site_nginx_files', function (Blueprint $table) {
            $table->dropColumn(['hash', 'last_synced_at']);
        });
    }
};
