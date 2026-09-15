<?php

namespace App\Actions\Backups;

use App\Actions\Database\EscapesShell;
use App\Contracts\StorageProviderContract;
use App\Events\BackupStatusChanged;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use App\Services\Storage\StorageProviderManager;
use RuntimeException;

class RunDatabaseBackup
{
    use EscapesShell;

    public function __construct(
        private SshService $sshService,
        private StorageProviderManager $storageProviderManager,
    ) {}

    public function trigger(
        ServerDatabase $serverDatabase,
        StorageProvider $storageProvider,
        string $triggeredBy,
        ?User $user = null,
    ): Backup {
        $backup = $serverDatabase->backups()->create([
            'storage_provider_id' => $storageProvider->id,
            'user_id' => $user?->id,
            'status' => 'pending',
            'triggered_by' => $triggeredBy,
        ]);

        RunBackupJob::dispatch($backup);

        return $backup;
    }

    public function execute(Backup $backup): void
    {
        $serverDatabase = $backup->serverDatabase;
        $server = $serverDatabase->server;
        $storageProvider = $backup->storageProvider;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $credential = $server->credential('database_password');
        if (! $credential) {
            throw new RuntimeException("No database password credential for server {$server->id}.");
        }

        $rootPassword = $credential->value;
        $driver = $this->storageProviderManager->forAccount($storageProvider);
        $path = $this->buildStoragePath($serverDatabase, $backup);
        $s3Uri = 's3://'.$driver->bucket().'/'.$path;
        $scratchDb = "flitops_verify_{$backup->id}";

        $connection = $this->sshService->connect($server);

        try {
            $this->ensureAwsCliInstalled($connection);

            $this->updateStatus($backup, 'dumping', ['started_at' => now()]);
            $this->streamDumpToStorage($connection, $server, $serverDatabase->name, $rootPassword, $driver, $s3Uri);

            $this->updateStatus($backup, 'verifying');
            $this->verifyBackup($connection, $server, $rootPassword, $driver, $s3Uri, $scratchDb);

            $backup->update([
                'status' => 'completed',
                'storage_path' => $path,
                'size_bytes' => $driver->size($path),
                'verified_at' => now(),
                'finished_at' => now(),
            ]);
            event(new BackupStatusChanged($server));

            $this->pruneOldBackups($serverDatabase, $driver);
        } catch (\Throwable $e) {
            try {
                $driver->delete($path);
            } catch (\Throwable) {
                // Best effort — nothing to clean up if the upload never completed.
            }

            $backup->update([
                'status' => 'failed',
                'error_message' => $server->redactSecrets($e->getMessage()),
                'finished_at' => now(),
            ]);
            event(new BackupStatusChanged($server));

            throw $e;
        } finally {
            $connection->disconnect();
        }
    }

    private function updateStatus(Backup $backup, string $status, array $extra = []): void
    {
        $backup->update(['status' => $status, ...$extra]);
        event(new BackupStatusChanged($backup->serverDatabase->server));
    }

    private function buildStoragePath(ServerDatabase $serverDatabase, Backup $backup): string
    {
        $timestamp = now()->format('Ymd_His');

        return "backups/{$serverDatabase->id}/{$timestamp}_{$backup->id}_{$serverDatabase->name}.sql.gz";
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
     * Stream a compressed dump straight from the managed server to the
     * bucket via the AWS CLI — the dump never touches the Flitops app
     * server, matching Laravel Forge's own backup.sh approach.
     */
    private function streamDumpToStorage(
        SshConnection $connection,
        Server $server,
        string $dbName,
        string $rootPassword,
        StorageProviderContract $driver,
        string $s3Uri,
    ): void {
        $dumpCmd = $this->buildDumpCommand($server, $dbName, $rootPassword);

        $inner = 'set -o pipefail; '.$dumpCmd.' | gzip -c | '.$driver->envPrefix().
            ' aws s3 cp - '.$this->escapeForShell($s3Uri).' '.$driver->awsCliArgs();

        $connection->exec('bash -c '.$this->escapeForShell($inner), 3600);
    }

    /**
     * Prove the backup is actually restorable (not just "completed") by
     * importing it into a throwaway scratch database, then dropping it.
     */
    private function verifyBackup(
        SshConnection $connection,
        Server $server,
        string $rootPassword,
        StorageProviderContract $driver,
        string $s3Uri,
        string $scratchDb,
    ): void {
        // Clean up any orphaned scratch database from a previous failed run.
        $this->dropScratchDatabase($connection, $server, $rootPassword, $scratchDb);
        $this->createScratchDatabase($connection, $server, $rootPassword, $scratchDb);

        try {
            // `-B`/`--create` make the dump self-contained with its own
            // CREATE DATABASE/USE (MySQL) or DROP/CREATE DATABASE/\connect
            // (Postgres) statements targeting the ORIGINAL database name.
            // Importing it unmodified here would overwrite the live
            // database instead of the scratch one, so those directive
            // lines are filtered out — the remaining table/data statements
            // then run against whichever database we explicitly connect
            // to below (the scratch one).
            $filterCmd = $this->buildVerifyFilterCommand($server->database_type);
            $importCmd = $this->buildImportCommand($server, $rootPassword, $scratchDb);

            $inner = 'set -o pipefail; '.$driver->envPrefix().' aws s3 cp '.
                $this->escapeForShell($s3Uri).' - '.$driver->awsCliArgs().
                ' | gunzip -c | '.$filterCmd.' | '.$importCmd;

            $connection->exec('bash -c '.$this->escapeForShell($inner), 1800);
        } finally {
            $this->dropScratchDatabase($connection, $server, $rootPassword, $scratchDb);
        }
    }

    private function buildDumpCommand(Server $server, string $dbName, string $rootPassword): string
    {
        $dbNameEsc = $this->escapeForShell($dbName);
        $passEsc = $this->escapeForShell($rootPassword);

        return match ($server->database_type) {
            'mysql' => "sudo mysqldump --user=root --password={$passEsc} --single-transaction --skip-lock-tables --routines --hex-blob -B {$dbNameEsc}",
            'mariadb' => "sudo mariadb-dump --user=root --password={$passEsc} --single-transaction --skip-lock-tables --routines --hex-blob -B {$dbNameEsc}",
            'postgresql' => "PGPASSWORD={$passEsc} sudo -u postgres pg_dump --clean --create -F p {$dbNameEsc}",
            default => throw new RuntimeException("Unsupported database type: {$server->database_type}"),
        };
    }

    private function buildVerifyFilterCommand(string $databaseType): string
    {
        return match ($databaseType) {
            'mysql', 'mariadb' => "grep -Fv -e 'CREATE DATABASE' -e 'USE `'",
            'postgresql' => "grep -Fv -e 'DROP DATABASE' -e 'CREATE DATABASE' -e '\\connect' -e 'ALTER DATABASE'",
            default => 'cat',
        };
    }

    private function buildImportCommand(Server $server, string $rootPassword, string $scratchDb): string
    {
        $passEsc = $this->escapeForShell($rootPassword);
        $scratchEsc = $this->escapeForShell($scratchDb);

        return match ($server->database_type) {
            'mysql' => "sudo mysql --user=root --password={$passEsc} {$scratchEsc}",
            'mariadb' => "sudo mariadb --user=root --password={$passEsc} {$scratchEsc}",
            'postgresql' => "PGPASSWORD={$passEsc} sudo -u postgres psql {$scratchEsc}",
            default => throw new RuntimeException("Unsupported database type: {$server->database_type}"),
        };
    }

    private function createScratchDatabase(SshConnection $connection, Server $server, string $rootPassword, string $scratchDb): void
    {
        $passEsc = $this->escapeForShell($rootPassword);
        $scratchEsc = $this->escapeForShell($scratchDb);

        $cmd = match ($server->database_type) {
            'mysql' => "sudo mysql --user=root --password={$passEsc} -e ".$this->escapeForShell("CREATE DATABASE IF NOT EXISTS `{$scratchDb}`"),
            'mariadb' => "sudo mariadb --user=root --password={$passEsc} -e ".$this->escapeForShell("CREATE DATABASE IF NOT EXISTS `{$scratchDb}`"),
            'postgresql' => "sudo -u postgres createdb {$scratchEsc}",
            default => throw new RuntimeException("Unsupported database type: {$server->database_type}"),
        };

        $connection->exec($cmd, 60);
    }

    private function dropScratchDatabase(SshConnection $connection, Server $server, string $rootPassword, string $scratchDb): void
    {
        $passEsc = $this->escapeForShell($rootPassword);
        $scratchEsc = $this->escapeForShell($scratchDb);

        $cmd = match ($server->database_type) {
            'mysql' => "sudo mysql --user=root --password={$passEsc} -e ".$this->escapeForShell("DROP DATABASE IF EXISTS `{$scratchDb}`"),
            'mariadb' => "sudo mariadb --user=root --password={$passEsc} -e ".$this->escapeForShell("DROP DATABASE IF EXISTS `{$scratchDb}`"),
            'postgresql' => "sudo -u postgres dropdb --if-exists {$scratchEsc}",
            default => 'true',
        };

        try {
            $connection->exec($cmd, 60);
        } catch (\Throwable) {
            // Best effort cleanup — don't let a failed drop mask the real error.
        }
    }

    private function pruneOldBackups(ServerDatabase $serverDatabase, StorageProviderContract $driver): void
    {
        $schedule = $serverDatabase->backupSchedule;
        $retentionCount = $schedule?->retention_count ?? 7;

        $completed = $serverDatabase->backups()
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
