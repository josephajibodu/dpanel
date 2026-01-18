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
        Schema::create('source_control_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->index(); // github, gitlab, bitbucket
            $table->string('provider_user_id'); // The user's ID from the provider
            $table->string('provider_username'); // e.g., 'josephajibodu' on GitHub
            $table->string('name'); // Display name from provider
            $table->string('email')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('token'); // OAuth token (encrypted)
            $table->text('refresh_token')->nullable(); // For token refresh (encrypted)
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('connected_at');
            $table->timestamps();

            $table->index(['user_id', 'provider']);
            $table->unique(['user_id', 'provider', 'provider_user_id'], 'user_provider_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_control_accounts');
    }
};
