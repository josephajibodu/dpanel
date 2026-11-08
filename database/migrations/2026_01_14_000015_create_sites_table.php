<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_control_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_database_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_name')->nullable();
            $table->string('domain')->index();
            $table->string('root_path')->nullable();
            $table->string('system_user', 32)->nullable();
            $table->json('aliases')->nullable();
            $table->string('directory')->default('/public');
            $table->string('repository')->nullable();
            $table->string('repository_provider', 50)->nullable();
            $table->string('branch')->default('main');
            $table->string('project_type', 50)->default('laravel');
            $table->string('php_version', 10)->default('8.3');
            $table->string('package_manager')->nullable();
            $table->string('build_command')->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->unsignedTinyInteger('provisioning_step')->nullable();
            $table->text('error_message')->nullable();
            $table->string('uptime_status', 20)->nullable();
            $table->timestamp('uptime_checked_at')->nullable();
            $table->unsignedInteger('uptime_consecutive_failures')->default(0);
            $table->string('deploy_key_id')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->string('deploy_webhook_id')->nullable();
            $table->boolean('auto_deploy')->default(false);
            $table->text('env_content')->nullable();
            $table->timestamp('deployment_started_at')->nullable();
            $table->timestamp('deployment_finished_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'system_user']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
