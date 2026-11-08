<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_control_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->index();
            $table->string('provider_user_id');
            $table->string('provider_username');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('repositories_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
            $table->unique(['user_id', 'provider', 'provider_user_id'], 'user_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_control_accounts');
    }
};
