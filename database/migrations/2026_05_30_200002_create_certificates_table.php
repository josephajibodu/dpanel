<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores metadata for centrally-issued SSL certificates.
     * The wildcard cert for `*.<free_domain>` lives here as a single row; the
     * actual cert/key bytes live on disk at the recorded paths so we never
     * round-trip private keys through Eloquent attributes.
     */
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('certificate_path');
            $table->string('private_key_path');
            $table->string('chain_path')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_renewed_at')->nullable();
            $table->timestamp('last_distribution_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
