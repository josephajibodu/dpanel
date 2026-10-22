<?php

namespace App\Actions\Backups;

use App\Actions\Database\EscapesShell;
use App\Actions\Database\KeepsSecretsOffCommandLine;
use App\Events\BackupStatusChanged;
use App\Exceptions\BackupFailedException;
use App\Jobs\RestoreBackupJob;
use App\Models\Backup;
use App\Models\Server;
use App\Services\Ssh\SshService;
use App\Services\Storage\StorageProviderManager;
use RuntimeException;

class RestoreDatabaseBackup
{
    use EscapesShell, KeepsSecretsOffCommandLine;

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
            throw new BackupFailedException("The server isn't active and connected, so the restore couldn't start. Check its connection and try again.");
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
            $this->withPrivateDirectory($connection, function (string $directory) use ($connection, $server, $rootPassword, $driver, $s3Uri): void {
                $restoreCmd = $this->buildRestoreCommand($server, $this->uploadMysqlRootCredentials($connection, $directory, $rootPassword));
                $awsCredentials = $this->uploadStorageCredentials($connection, $directory, $driver);

                $inner = 'set -o pipefail; '.$awsCredentials.' aws s3 cp '.
                    $this->escapeForShell($s3Uri).' - '.$driver->awsCliArgs().
                    ' | gunzip -c | '.$restoreCmd;

                $connection->exec('bash -c '.$this->escapeForShell($inner), 3600);
            });

            $backup->update([
                'restore_status' => 'completed',
                'restored_at' => now(),
                'restore_error' => null,
            ]);
            event(new BackupStatusChanged($server));
        } catch (\Throwable $e) {
            $backup->markRestoreAsFailed($e);

            throw $e;
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * The dump is self-contained (`-B`/`--create` in RunDatabaseBackup), so
     * restoring doesn't need a target database name — MySQL's dump includes
     * its own `CREATE DATABASE`/`USE`, and Postgres's dump includes its own
     * `DROP DATABASE`/`CREATE DATABASE`/`\connect`. Postgres just needs an
     * initial connection to any existing database to run those from, and
     * signs in as the local postgres system user, so it needs no password.
     */
    private function buildRestoreCommand(Server $server, string $mysqlCredentials): string
    {
        $credentialsOption = '--defaults-extra-file='.$this->escapeForShell($mysqlCredentials);

        return match ($server->database_type) {
            'mysql' => "sudo mysql {$credentialsOption}",
            'mariadb' => "sudo mariadb {$credentialsOption}",
            'postgresql' => 'sudo -u postgres psql -d postgres',
            default => throw new RuntimeException("Unsupported database type: {$server->database_type}"),
        };
    }
}
