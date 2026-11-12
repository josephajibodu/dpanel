<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The folder inside the repository that holds the app, for a repository
     * that keeps more than one app. Null means the top of the repository.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('app_directory')->nullable()->after('directory');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('app_directory');
        });
    }
};
