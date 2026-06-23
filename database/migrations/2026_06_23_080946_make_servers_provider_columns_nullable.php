<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_account_id')->nullable()->change();
            $table->string('size', 50)->nullable()->change();
            $table->string('region', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_account_id')->nullable(false)->change();
            $table->string('size', 50)->nullable(false)->change();
            $table->string('region', 50)->nullable(false)->change();
        });
    }
};
