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
        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('provider_region_id')->nullable()->after('region')->constrained()->nullOnDelete();
            $table->foreignId('provider_size_id')->nullable()->after('size')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropForeign(['provider_region_id']);
            $table->dropForeign(['provider_size_id']);
            $table->dropColumn(['provider_region_id', 'provider_size_id']);
        });
    }
};
