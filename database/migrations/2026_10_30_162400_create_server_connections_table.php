<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A connection lets one server (the client) reach the database and Redis
     * on another (the host) through the host's firewall.
     */
    public function up(): void
    {
        Schema::create('server_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('host_address', 45);
            $table->string('client_address', 45);
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'client_server_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_connections');
    }
};
