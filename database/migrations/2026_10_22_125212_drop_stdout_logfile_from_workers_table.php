<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Worker log files now live at a path FlitOps picks (see
     * Worker::logFilePath()), so the user-chosen path is no longer stored.
     */
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('stdout_logfile');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('stdout_logfile', 500)->nullable()->after('redirect_stderr');
        });
    }
};
