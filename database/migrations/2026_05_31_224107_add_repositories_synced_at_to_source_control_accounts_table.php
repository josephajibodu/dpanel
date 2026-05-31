<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_control_accounts', function (Blueprint $table) {
            $table->timestamp('repositories_synced_at')->nullable()->after('connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('source_control_accounts', function (Blueprint $table) {
            $table->dropColumn('repositories_synced_at');
        });
    }
};
