<?php

namespace App\Jobs;

use App\Actions\Backups\EnsureBackupStorageIsReachable;
use App\Actions\Backups\RestoreDatabaseBackup;
use App\Models\Backup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RestoreBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Backup $backup
    ) {
        $this->onQueue('backups');
    }

    /**
     * Execute the job.
     */
    public function handle(EnsureBackupStorageIsReachable $ensureStorageIsReachable, RestoreDatabaseBackup $action): void
    {
        $ensureStorageIsReachable->execute($this->backup, 'restore');

        $action->execute($this->backup);
    }

    /**
     * Failures before the action's own error handling (an unreachable server,
     * a job timeout) would otherwise leave the restore stuck as running, so
     * it is marked failed here too. The full error is logged for debugging.
     */
    public function failed(\Throwable $exception): void
    {
        $server = $this->backup->targetServer();

        Log::error("RestoreBackupJob failed for backup {$this->backup->id}", [
            'backup_id' => $this->backup->id,
            'server_id' => $server->id,
            'exception' => get_class($exception),
            'message' => $this->backup->redactSecrets($exception->getMessage()),
        ]);

        $backup = $this->backup->fresh();

        if ($backup?->restore_status === 'running') {
            $backup->markRestoreAsFailed($exception);
        }
    }
}
