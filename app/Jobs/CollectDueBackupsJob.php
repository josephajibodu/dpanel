<?php

namespace App\Jobs;

use App\Actions\Backups\RunDatabaseBackup;
use App\Actions\Backups\RunSqliteBackup;
use App\Enums\PlanFeature;
use App\Models\BackupSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class CollectDueBackupsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Dispatch one RunBackupJob per due, enabled schedule — each backup runs
     * as its own queued job so one slow or unreachable server can't delay
     * the rest, matching CollectAllServerMetricsJob's fan-out pattern. A
     * schedule whose team owner's plan has no backups is skipped, not
     * deleted, so it resumes after an upgrade.
     */
    public function handle(RunDatabaseBackup $databaseBackup, RunSqliteBackup $sqliteBackup): void
    {
        BackupSchedule::query()
            ->where('enabled', true)
            ->where('next_run_at', '<=', now())
            ->with('serverDatabase.server.team.owner', 'site.server.team.owner', 'storageProvider')
            ->each(function (BackupSchedule $schedule) use ($databaseBackup, $sqliteBackup) {
                // Advance next_run_at inside a transaction before dispatching
                // so a slow tick can't dispatch the same schedule twice.
                $dueNow = DB::transaction(function () use ($schedule) {
                    $locked = BackupSchedule::query()
                        ->whereKey($schedule->id)
                        ->where('next_run_at', '<=', now())
                        ->lockForUpdate()
                        ->first();

                    if (! $locked) {
                        return false;
                    }

                    $locked->update(['next_run_at' => $locked->computeNextRunAt()]);

                    return true;
                });

                if (! $dueNow) {
                    return;
                }

                $server = $schedule->site?->server ?? $schedule->serverDatabase->server;
                if (! $server->isReady() || ! $server->team?->planIncludes(PlanFeature::DatabaseBackups)) {
                    return;
                }

                if ($schedule->site) {
                    $sqliteBackup->trigger($schedule->site, $schedule->storageProvider, 'scheduled');
                } else {
                    $databaseBackup->trigger($schedule->serverDatabase, $schedule->storageProvider, 'scheduled');
                }
            });
    }
}
