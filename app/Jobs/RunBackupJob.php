<?php

namespace App\Jobs;

use App\Actions\Backups\RunDatabaseBackup;
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
    public function handle(RunDatabaseBackup $action): void
    {
        $action->execute($this->backup);
    }

    public function failed(\Throwable $exception): void
    {
        $server = $this->backup->serverDatabase->server;

        Log::error("RunBackupJob failed for backup {$this->backup->id}", [
            'backup_id' => $this->backup->id,
            'server_id' => $server->id,
            'exception' => get_class($exception),
            'message' => $server->redactSecrets($exception->getMessage()),
        ]);

        $notifiable = $this->backup->user ?? $server->team->user;
        $notifiable?->notify(new DatabaseBackupFailed($this->backup));
    }
}
