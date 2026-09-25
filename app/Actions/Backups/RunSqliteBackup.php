<?php

namespace App\Actions\Backups;

use App\Actions\Database\EscapesShell;
use App\Contracts\StorageProviderContract;
use App\Events\BackupStatusChanged;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use App\Services\Storage\StorageProviderManager;
use RuntimeException;

class RunSqliteBackup
{
    use EscapesShell, ManagesBackupLifecycle;

    /**
     * Takes a transactionally-consistent copy of a live SQLite database via
     * `VACUUM INTO` (safe while the app keeps writing, and folds in any WAL
     * contents). Runs through PHP's PDO driver, which every provisioned
     * server has, so the sqlite3 CLI isn't needed. Args: source, target.
     */
    private const SNAPSHOT_SCRIPT = <<<'PHP'
        $db = new PDO("sqlite:".$argv[1]);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_TIMEOUT, 60);
        $db->exec("VACUUM INTO ".$db->quote($argv[2]));
        PHP;

    /**
     * Opens a restored copy and fails unless `PRAGMA integrity_check`
     * reports "ok". Args: database file.
     */
    private const INTEGRITY_CHECK_SCRIPT = <<<'PHP'
        $db = new PDO("sqlite:".$argv[1]);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $result = $db->query("PRAGMA integrity_check")->fetchColumn();
        if ($result !== "ok") {
            fwrite(STDERR, "SQLite integrity check failed: ".$result);
            exit(1);
        }
        PHP;

    public function __construct(
        private SshService $sshService,
        private StorageProviderManager $storageProviderManager,
    ) {}

    public function trigger(
        Site $site,
        StorageProvider $storageProvider,
        string $triggeredBy,
        ?User $user = null,
    ): Backup {
        $backup = $site->backups()->create([
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
        $site = $backup->site;
        $server = $site->server;
        $storageProvider = $backup->storageProvider;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $driver = $this->storageProviderManager->forAccount($storageProvider);
        $path = $this->buildStoragePath($site, $backup);
        $s3Uri = 's3://'.$driver->bucket().'/'.$path;
        $workDir = "/tmp/flitops-sqlite-backup-{$backup->id}";

        $connection = $this->sshService->connect($server);

        try {
            $this->ensureAwsCliInstalled($connection);

            $this->updateStatus($backup, 'dumping', ['started_at' => now()]);
            $this->prepareWorkDir($connection, $workDir);
            $this->streamSnapshotToStorage($connection, $site->sqliteDatabasePath(), $workDir, $driver, $s3Uri);

            $this->updateStatus($backup, 'verifying');
            $this->verifyBackup($connection, $workDir, $driver, $s3Uri);

            $backup->update([
                'status' => 'completed',
                'storage_path' => $path,
                'size_bytes' => $driver->size($path),
                'verified_at' => now(),
                'finished_at' => now(),
            ]);
            event(new BackupStatusChanged($server));

            $this->pruneOldBackups($backup, $driver);
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
            try {
                $connection->exec('rm -rf '.$this->escapeForShell($workDir), 60);
            } catch (\Throwable) {
                // Best effort — don't let a failed cleanup mask the real error.
            }

            $connection->disconnect();
        }
    }

    private function buildStoragePath(Site $site, Backup $backup): string
    {
        $timestamp = now()->format('Ymd_His');

        return "backups/sites/{$site->id}/{$timestamp}_{$backup->id}_database.sqlite.gz";
    }

    /**
     * A private scratch directory for the snapshot and the verify copy,
     * cleared first in case a previous attempt for this backup left one.
     */
    private function prepareWorkDir(SshConnection $connection, string $workDir): void
    {
        $dirEsc = $this->escapeForShell($workDir);

        $connection->exec("rm -rf {$dirEsc} && mkdir -m 700 {$dirEsc}", 60);
    }

    /**
     * Snapshot the live file into the scratch directory, then stream it
     * compressed straight to the bucket — like the server database backups,
     * the data never touches the Flitops app server.
     */
    private function streamSnapshotToStorage(
        SshConnection $connection,
        string $sqlitePath,
        string $workDir,
        StorageProviderContract $driver,
        string $s3Uri,
    ): void {
        $sourceEsc = $this->escapeForShell($sqlitePath);
        $snapshotEsc = $this->escapeForShell("{$workDir}/snapshot.sqlite");

        $inner = 'set -o pipefail; '.
            "test -f {$sourceEsc} || { echo \"SQLite database not found at {$sqlitePath}\" >&2; exit 1; }; ".
            'php -r '.$this->escapeForShell(self::SNAPSHOT_SCRIPT)." {$sourceEsc} {$snapshotEsc} && ".
            "gzip -c {$snapshotEsc} | ".$driver->envPrefix().
            ' aws s3 cp - '.$this->escapeForShell($s3Uri).' '.$driver->awsCliArgs();

        $connection->exec('bash -c '.$this->escapeForShell($inner), 3600);
    }

    /**
     * Prove the backup is actually usable (not just "completed") by pulling
     * the uploaded object back down, decompressing it and running SQLite's
     * integrity check against it.
     */
    private function verifyBackup(
        SshConnection $connection,
        string $workDir,
        StorageProviderContract $driver,
        string $s3Uri,
    ): void {
        $verifyEsc = $this->escapeForShell("{$workDir}/verify.sqlite");

        $inner = 'set -o pipefail; '.$driver->envPrefix().' aws s3 cp '.
            $this->escapeForShell($s3Uri).' - '.$driver->awsCliArgs().
            " | gunzip -c > {$verifyEsc} && ".
            'php -r '.$this->escapeForShell(self::INTEGRITY_CHECK_SCRIPT)." {$verifyEsc}";

        $connection->exec('bash -c '.$this->escapeForShell($inner), 1800);
    }
}
