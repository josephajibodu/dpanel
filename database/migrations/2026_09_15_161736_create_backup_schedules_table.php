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
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_database_id')->unique()->constrained('server_databases')->cascadeOnDelete();
            $table->foreignId('storage_provider_id')->constrained()->restrictOnDelete();
            $table->string('frequency', 20)->default('daily');
            $table->unsignedInteger('retention_count')->default(7);
            $table->boolean('enabled')->default(false);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
    }
};
