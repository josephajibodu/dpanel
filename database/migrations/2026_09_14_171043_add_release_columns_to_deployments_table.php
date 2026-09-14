<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            // Explicit release folder name for this deployment. Null means "use my
            // own ulid" (normal deploys); set on rollback rows to reuse an older
            // release folder that already exists on disk instead of creating one.
            $table->string('release_path')->nullable()->after('duration_seconds');

            $table->foreignId('rollback_of_deployment_id')
                ->nullable()
                ->after('release_path')
                ->constrained('deployments')
                ->nullOnDelete();
        });

        // Adding a foreign key column to SQLite forces Laravel to rebuild the whole
        // table (SQLite can't ALTER TABLE ADD a FK constraint directly), and that
        // rebuild recreates indexes from Laravel's own metadata — which knows
        // nothing about the raw partial WHERE clause on deployments_active_unique,
        // so it comes back as a plain (non-partial) unique index. Re-issuing the
        // original raw SQL restores it; on Postgres (production) the ADD COLUMN
        // above never triggers a rebuild, so this is just a harmless no-op there.
        DB::statement('DROP INDEX IF EXISTS deployments_active_unique');
        DB::statement(
            "CREATE UNIQUE INDEX deployments_active_unique ON deployments (site_id) WHERE status IN ('pending', 'running')"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rollback_of_deployment_id');
            $table->dropColumn('release_path');
        });
    }
};
