<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_domains', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('type', 32);
            $table->boolean('is_primary')->default(false);
            $table->boolean('wildcard_enabled')->default(false);
            $table->string('www_redirect', 32)->default('none');
            $table->boolean('is_enabled')->default(true);
            $table->string('status')->default('active');
            $table->string('cloudflare_dns_record_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('ssl_enabled_at')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->unsignedTinyInteger('ssl_expiry_alert_threshold')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_domains');
    }
};
