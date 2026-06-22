<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_nginx_files', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('section');
            $table->string('path');
            $table->longText('content')->nullable();
            $table->string('sync_status')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_nginx_files');
    }
};
