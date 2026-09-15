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
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_database_id')->constrained('server_databases')->cascadeOnDelete();
            $table->foreignId('storage_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('triggered_by', 20);
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('restore_status', 20)->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->text('restore_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
