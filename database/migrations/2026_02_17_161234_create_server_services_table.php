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
        Schema::create('server_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50)->index();
            $table->json('type_data')->nullable();
            $table->string('name')->nullable();
            $table->string('version', 50)->nullable();
            $table->string('installed_version', 50)->nullable();
            $table->string('unit', 100)->nullable();
            $table->string('logs', 500)->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_services');
    }
};
