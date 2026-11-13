<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('octane_server', 20)->nullable()->after('single_page_app');
            $table->unsignedSmallInteger('octane_port')->nullable()->after('octane_server');
            $table->unique(['server_id', 'octane_port']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'octane_port']);
            $table->dropColumn(['octane_server', 'octane_port']);
        });
    }
};
