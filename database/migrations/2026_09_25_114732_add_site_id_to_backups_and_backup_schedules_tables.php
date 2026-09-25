<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A backup (and its schedule) now targets either a server database or a
     * site's SQLite file, so server_database_id becomes nullable and site_id
     * is added alongside it.
     */
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->foreignId('server_database_id')->nullable()->change();
            $table->foreignId('site_id')->nullable()->after('server_database_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->foreignId('server_database_id')->nullable()->change();
            $table->foreignId('site_id')->nullable()->unique()->after('server_database_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->dropUnique(['site_id']);
            $table->dropConstrainedForeignId('site_id');
            $table->foreignId('server_database_id')->nullable(false)->change();
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->foreignId('server_database_id')->nullable(false)->change();
        });
    }
};
