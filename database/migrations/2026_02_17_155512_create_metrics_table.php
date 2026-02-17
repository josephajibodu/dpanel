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
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->decimal('load', 10, 2)->nullable();
            $table->unsignedBigInteger('memory_total')->nullable();
            $table->unsignedBigInteger('memory_used')->nullable();
            $table->unsignedBigInteger('memory_free')->nullable();
            $table->unsignedBigInteger('disk_total')->nullable();
            $table->unsignedBigInteger('disk_used')->nullable();
            $table->unsignedBigInteger('disk_free')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
