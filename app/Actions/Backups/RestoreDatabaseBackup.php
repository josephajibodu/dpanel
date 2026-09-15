<?php

namespace App\Actions\Backups;

use App\Actions\Database\EscapesShell;
use App\Events\BackupStatusChanged;
use App\Jobs\RestoreBackupJob;
use App\Models\Backup;
use App\Models\Server;
use App\Services\Ssh\SshService;
use App\Services\Storage\StorageProviderManager;
use RuntimeException;

class RestoreDatabaseBackup
{
    use EscapesShell;

    public function __construct(
        private SshService $sshService,
        private StorageProviderManager $storageProviderManager,
    ) {}

    public function trigger(Backup $backup): void
    {
        $backup->update(['restore_status' => 'running', 'restore_error' => null]);
        event(new BackupStatusChanged($backup->serverDatabase->server));

        RestoreBackupJob::dispatch($backup);
    }

    public function execute(Backup $backup): void
    {
        $serverDatabase = $backup->serverDatabase;
        $server = $serverDatabase->server;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        if (! $backup->storage_path) {
            throw new RuntimeException("Backup {$backup->id} has no stored file to restore.");
        }

        $credential = $server->credential('database_password');
        if (! $credential) {
            throw new RuntimeException("No database password credential for server {$server->id}.");
        }

        $rootPassword = $credential->value;
        $driver = $this->storageProviderManager->forAccount($backup->storageProvider);
        $s3Uri = 's3://'.$driver->bucket().'/'.$backup->storage_path;

        $connection = $this->sshService->connect($server);

        try {
            $restoreCmd = $this->buildRestoreCommand($server, $rootPassword);

            $inner = 'set -o pipefail; '.$driver->envPrefix().' aws s3 cp '.
                $this->escapeForShell($s3Uri).' - '.$driver->awsCliArgs().
                ' | gunzip -c | '.$restoreCmd;

            $connection->exec('bash -c '.$this->escapeForShell($inner), 3600);

            $backup->update([
                'restore_status' => 'completed',
                'restored_at' => now(),
                'restore_error' => null,
            ]);
        } catch (\Throwable $e) {
            $backup->update([
                'restore_status' => 'failed',
                'restore_error' => $server->redactSecrets($e->getMessage()),
            ]);

            throw $e;
        } finally {
            $connection->disconnect();
            event(new BackupStatusChanged($server));
        }
    }

    /**
     * The dump is self-contained (`-B`/`--create` in RunDatabaseBackup), so
     * restoring doesn't need a target database name — MySQL's dump includes
     * its own `CREATE DATABASE`/`USE`, and Postgres's dump includes its own
     * `DROP DATABASE`/`CREATE DATABASE`/`\connect`. Postgres just needs an
     * initial connection to any existing database to run those from.
     */
    private function buildRestoreCommand(Server $server, string $rootPassword): string
    {
        $passEsc = $this->escapeForShell($rootPassword);

        return match ($server->database_type) {
            'mysql' => "sudo mysql --user=root --password={$passEsc}",
            'mariadb' => "sudo mariadb --user=root --password={$passEsc}",
            'postgresql' => "PGPASSWORD={$passEsc} sudo -u postgres psql -d postgres",
            default => throw new RuntimeException("Unsupported database type: {$server->database_type}"),
        };
    }
}
