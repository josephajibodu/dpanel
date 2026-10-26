<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * New servers are provisioned with Node.js 24. Servers that already exist
     * were all provisioned with Node.js 20, so they are recorded as such.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('node_version', 10)->default('24')->after('php_version');
            $table->string('node_status', 20)->default('active')->after('node_version');
        });

        DB::table('servers')->update(['node_version' => '20']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['node_version', 'node_status']);
        });
    }
};
