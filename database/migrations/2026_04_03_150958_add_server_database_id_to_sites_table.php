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
        if (Schema::hasColumn('sites', 'server_database_id')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('server_database_id')->nullable()->after('project_type')->constrained('server_databases')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_database_id');
        });
    }
};
