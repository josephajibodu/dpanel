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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->index();
            $table->json('aliases')->nullable();
            $table->string('directory')->default('/public');
            $table->string('repository')->nullable();
            $table->string('repository_provider', 50)->nullable();
            $table->string('branch')->default('main');
            $table->string('project_type', 50)->default('laravel');
            $table->string('php_version', 10)->default('8.3');
            $table->string('status', 50)->default('pending')->index();
            $table->string('deploy_key_id')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->boolean('auto_deploy')->default(false);
            $table->timestamp('deployment_started_at')->nullable();
            $table->timestamp('deployment_finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
