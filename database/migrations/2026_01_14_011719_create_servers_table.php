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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->index();
            $table->string('provider_server_id')->nullable()->index();
            $table->string('name');
            $table->string('size', 50);
            $table->string('region', 50);
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('private_ip_address', 45)->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->string('php_version', 10)->default('8.3');
            $table->string('database_type', 20)->default('mysql');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->text('sudo_password')->nullable();
            $table->text('database_password')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_ssh_connection_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
