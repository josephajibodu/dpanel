<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('certificate_path');
            $table->string('private_key_path');
            $table->string('chain_path')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('expiry_alert_threshold')->nullable();
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
