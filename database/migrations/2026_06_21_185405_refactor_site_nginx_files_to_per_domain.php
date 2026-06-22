<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clear existing records — path format is changing to per-domain
        \DB::table('site_nginx_files')->delete();

        Schema::table('site_nginx_files', function (Blueprint $table) {
            $table->dropUnique('site_nginx_files_site_id_path_unique');
            $table->foreignId('site_domain_id')->nullable()->after('site_id')->constrained('site_domains')->cascadeOnDelete();
            $table->unique(['site_domain_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::table('site_nginx_files', function (Blueprint $table) {
            $table->dropForeign(['site_domain_id']);
            $table->dropUnique(['site_domain_id', 'path']);
            $table->dropColumn('site_domain_id');
            $table->unique(['site_id', 'path']);
        });
    }
};
