<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_ssh_key', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ssh_key_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('system_user', 32)->default(config('server.user'));
            $table->string('status', 20)->default('pending');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'ssh_key_id', 'system_user']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_ssh_key');
    }
};
