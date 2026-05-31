<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_control_repositories', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('source_control_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_repo_id');
            $table->string('name');
            $table->string('full_name')->index();
            $table->string('ssh_url');
            $table->string('html_url');
            $table->string('default_branch')->default('main');
            $table->boolean('private')->default(false);
            $table->timestamps();

            $table->unique(['source_control_account_id', 'provider_repo_id'], 'sc_repo_account_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_control_repositories');
    }
};
