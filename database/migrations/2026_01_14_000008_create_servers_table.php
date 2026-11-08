<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('provider_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->index();
            $table->string('provider_server_id')->nullable()->index();
            $table->string('cloud_provider_url')->nullable();
            $table->string('name');
            $table->string('type')->default('app')->index();
            $table->string('size', 50)->nullable();
            $table->foreignId('provider_size_id')->nullable()->constrained()->nullOnDelete();
            $table->string('region', 50)->nullable();
            $table->foreignId('provider_region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('private_ip_address', 45)->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('provisioning_step')->default(0);
            $table->string('connection_status')->default('unknown');
            $table->string('php_version', 10)->nullable();
            $table->string('web_server', 20)->nullable();
            $table->string('node_version', 10)->default('24');
            $table->string('node_status', 20)->default('active');
            $table->string('database_type', 20)->nullable();
            $table->string('ubuntu_version')->nullable();
            $table->string('timezone')->default('UTC');
            $table->text('notes')->nullable();
            $table->boolean('archived')->default(false)->index();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->text('sudo_password')->nullable();
            $table->text('database_password')->nullable();
            $table->text('local_public_key')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_ssh_connection_at')->nullable();
            $table->string('host_key_type')->nullable();
            $table->string('host_key_fingerprint')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
