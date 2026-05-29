<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Guarantee at most one in-progress (pending or running) deployment per site.
     *
     * This partial unique index is the database-level backstop for the
     * check-then-create race in TriggerDeploymentAction: two concurrent requests
     * can both pass the application "is one already running?" check, but only one
     * INSERT can win the index. Terminal deployments (finished/failed/cancelled)
     * are excluded, so a site may still accumulate any number of those.
     */
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX deployments_active_unique ON deployments (site_id) WHERE status IN ('pending', 'running')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS deployments_active_unique');
    }
};
