<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sites created before frameworks existed keep a null framework and are
     * shown by their project type.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('framework')->nullable()->after('project_type');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('framework');
        });
    }
};
