<?php

namespace App\Jobs;

use App\Actions\Backups\EnsureBackupStorageIsReachable;
use App\Actions\Backups\RunDatabaseBackup;
use App\Actions\Backups\RunSqliteBackup;
use App\Models\Backup;
use App\Notifications\DatabaseBackupFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunBackupJob implements ShouldQueue
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
    public function handle(EnsureBackupStorageIsReachable $ensureStorageIsReachable): void
    {
        $ensureStorageIsReachable->execute($this->backup);

        $action = $this->backup->isSqlite()
            ? app(RunSqliteBackup::class)
            : app(RunDatabaseBackup::class);

        $action->execute($this->backup);
    }

    /**
     * Failures before the action's own error handling (an unreachable server,
     * a job timeout) would otherwise leave the backup stuck mid-run, so it is
     * marked failed here too. The full error is logged for debugging; the
     * user only sees the safe message on the backup.
     */
    public function failed(\Throwable $exception): void
    {
        $server = $this->backup->targetServer();

        Log::error("RunBackupJob failed for backup {$this->backup->id}", [
            'backup_id' => $this->backup->id,
            'server_id' => $server->id,
            'status' => $this->backup->status,
            'exception' => get_class($exception),
            'message' => $this->backup->redactSecrets($exception->getMessage()),
        ]);

        $backup = $this->backup->fresh();

        if (! $backup) {
            return;
        }

        if ($backup->status !== 'failed') {
            $backup->markAsFailed($exception);
        }

        $notifiable = $backup->user ?? $server->team?->owner ?? $server->user;
        $notifiable?->notify(new DatabaseBackupFailed($backup));
    }
}
