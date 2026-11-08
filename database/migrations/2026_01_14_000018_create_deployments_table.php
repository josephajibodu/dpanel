<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The partial unique index allows only one pending or running deployment
     * per site. The schema builder can't express a WHERE clause, so it's raw SQL.
     */
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('commit_hash', 40)->nullable();
            $table->string('commit_message')->nullable();
            $table->string('commit_author')->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('release_path')->nullable();
            $table->foreignId('rollback_of_deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->string('triggered_by', 50)->default('manual');
            $table->timestamps();
        });

        DB::statement(
            "CREATE UNIQUE INDEX deployments_active_unique ON deployments (site_id) WHERE status IN ('pending', 'running')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
