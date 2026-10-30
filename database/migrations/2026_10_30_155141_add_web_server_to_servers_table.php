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
     * A server is now described by the services chosen for it: a null
     * php_version, web_server or database_type means that service isn't
     * installed. Existing servers are backfilled from their type's preset.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('web_server', 20)->nullable()->after('php_version');
            $table->string('php_version', 10)->nullable()->default(null)->change();
            $table->string('database_type', 20)->nullable()->default(null)->change();
        });

        DB::table('servers')->whereIn('type', ['app', 'web', 'loadbalancer'])->update(['web_server' => 'nginx']);
        DB::table('servers')->whereIn('type', ['database', 'cache', 'loadbalancer'])->update(['php_version' => null]);
        DB::table('servers')->whereIn('type', ['web', 'worker', 'cache', 'loadbalancer'])->update(['database_type' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('servers')->whereNull('php_version')->update(['php_version' => '8.3']);
        DB::table('servers')->whereNull('database_type')->update(['database_type' => 'mysql']);

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('web_server');
            $table->string('php_version', 10)->default('8.3')->change();
            $table->string('database_type', 20)->default('mysql')->change();
        });
    }
};
