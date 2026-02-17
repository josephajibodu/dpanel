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
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->text('command');
            $table->string('user', 100)->nullable();
            $table->boolean('auto_start')->default(true);
            $table->boolean('auto_restart')->default(true);
            $table->unsignedSmallInteger('numprocs')->default(1);
            $table->boolean('redirect_stderr')->default(true);
            $table->string('stdout_logfile', 500)->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
