<?php

namespace App\Actions\Backups;

use App\Contracts\StorageProviderContract;
use App\Events\BackupStatusChanged;
use App\Models\Backup;
use App\Services\Ssh\SshConnection;

/**
 * Shared plumbing for backup actions, whatever the backed-up target is
 * (a server database or a site's SQLite file).
 */
trait ManagesBackupLifecycle
{
    private function updateStatus(Backup $backup, string $status, array $extra = []): void
    {
        $backup->update(['status' => $status, ...$extra]);
        event(new BackupStatusChanged($backup->targetServer()));
    }

    /**
     * The AWS CLI isn't part of provisioning yet — install it lazily so this
     * feature works on already-provisioned servers too, not just new ones.
     */
    private function ensureAwsCliInstalled(SshConnection $connection): void
    {
        $connection->exec(
            'command -v aws >/dev/null 2>&1 || (sudo apt-get update -qq && sudo apt-get install -y -qq awscli)',
            180,
        );
    }

    /**
     * Keep only the newest completed backups of the same target, per its
     * schedule's retention count (7 when no schedule exists).
     */
    private function pruneOldBackups(Backup $backup, StorageProviderContract $driver): void
    {
        $target = $backup->isSqlite() ? $backup->site : $backup->serverDatabase;
        $retentionCount = $target->backupSchedule?->retention_count ?? 7;

        $completed = $target->backups()
            ->where('status', 'completed')
            ->orderByDesc('finished_at')
            ->get();

        $stale = $completed->slice($retentionCount);

        foreach ($stale as $old) {
            if ($old->storage_path) {
                try {
                    $driver->delete($old->storage_path);
                } catch (\Throwable) {
                    // Best effort — the row is removed either way below.
                }
            }

            $old->delete();
        }
    }
}
