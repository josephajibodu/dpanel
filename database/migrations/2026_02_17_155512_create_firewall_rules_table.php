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
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50)->nullable();
            $table->string('protocol', 20)->nullable();
            $table->string('port', 50)->nullable();
            $table->string('source', 255)->nullable();
            $table->string('mask', 50)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('firewall_rules');
    }
};
