<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->text('command');
            $table->string('user', 100)->nullable();
            $table->boolean('auto_start')->default(true);
            $table->boolean('auto_restart')->default(true);
            $table->unsignedSmallInteger('numprocs')->default(1);
            $table->boolean('redirect_stderr')->default(true);
            $table->boolean('restart_on_deploy')->default(false);
            $table->string('status', 50)->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
