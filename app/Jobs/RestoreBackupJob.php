<?php

namespace App\Jobs;

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
    public function handle(RestoreDatabaseBackup $action): void
    {
        $action->execute($this->backup);
    }

    public function failed(\Throwable $exception): void
    {
        $server = $this->backup->targetServer();

        Log::error("RestoreBackupJob failed for backup {$this->backup->id}", [
            'backup_id' => $this->backup->id,
            'server_id' => $server->id,
            'exception' => get_class($exception),
            'message' => $server->redactSecrets($exception->getMessage()),
        ]);
    }
}
