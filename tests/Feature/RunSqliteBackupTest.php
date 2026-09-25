<?php

use App\Actions\Backups\RunSqliteBackup;
use App\Exceptions\SshCommandException;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\StorageProvider;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function mockSqliteBackupDriver(): CloudflareR2Driver
{
    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('envPrefix')->andReturn("AWS_ACCESS_KEY_ID='x' AWS_SECRET_ACCESS_KEY='y'");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");
    $driver->shouldReceive('size')->andReturn(4096);

    return $driver;
}

/**
 * @param  array<int, string>  $commands  collects every command sent over SSH
 */
function fakeSqliteBackupConnection(array &$commands, ?callable $failWhen = null): SshConnection
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$commands, $failWhen) {
        $commands[] = $cmd;

        if ($failWhen && $failWhen($cmd)) {
            throw new SshCommandException($cmd, 1, '', 'boom');
        }

        return '';
    });
    $connection->shouldReceive('disconnect')->andReturn(null);

    return $connection;
}

function bindSqliteBackupServices(SshConnection $connection, $driver): void
{
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);
}

beforeEach(function () {
    $this->server = Server::factory()->create();
    $this->site = Site::factory()->forServer($this->server)->create(['server_database_id' => null]);
    $this->storageProvider = StorageProvider::factory()->create();
});

it('queues a backup targeting the site', function () {
    Queue::fake();

    $backup = app(RunSqliteBackup::class)->trigger($this->site, $this->storageProvider, 'manual');

    expect($backup->site_id)->toBe($this->site->id)
        ->and($backup->server_database_id)->toBeNull()
        ->and($backup->status)->toBe('pending');
    Queue::assertPushed(RunBackupJob::class, fn ($job) => $job->backup->is($backup));
});

it('snapshots, uploads, verifies and records a completed backup', function () {
    $commands = [];
    bindSqliteBackupServices(fakeSqliteBackupConnection($commands), mockSqliteBackupDriver());

    $backup = Backup::factory()->forSite($this->site)->create([
        'storage_provider_id' => $this->storageProvider->id,
    ]);

    app(RunSqliteBackup::class)->execute($backup);

    $backup->refresh();
    expect($backup->status)->toBe('completed')
        ->and($backup->storage_path)->toStartWith("backups/sites/{$this->site->id}/")
        ->and($backup->storage_path)->toEndWith('_database.sqlite.gz')
        ->and($backup->size_bytes)->toBe(4096)
        ->and($backup->verified_at)->not->toBeNull()
        ->and($backup->finished_at)->not->toBeNull();

    $all = implode("\n", $commands);
    expect($all)->toContain($this->site->sqliteDatabasePath())
        ->and($all)->toContain('VACUUM INTO')
        ->and($all)->toContain('aws s3 cp - ')
        ->and($all)->toContain('PRAGMA integrity_check')
        ->and(end($commands))->toBe("rm -rf '/tmp/flitops-sqlite-backup-{$backup->id}'");
});

it('marks the backup failed, removes the object and cleans up when the snapshot fails', function () {
    $commands = [];
    $connection = fakeSqliteBackupConnection($commands, fn (string $cmd) => str_contains($cmd, 'VACUUM INTO'));

    $driver = mockSqliteBackupDriver();
    $driver->shouldReceive('delete')->once();
    bindSqliteBackupServices($connection, $driver);

    $backup = Backup::factory()->forSite($this->site)->create([
        'storage_provider_id' => $this->storageProvider->id,
    ]);

    expect(fn () => app(RunSqliteBackup::class)->execute($backup))
        ->toThrow(SshCommandException::class);

    $backup->refresh();
    expect($backup->status)->toBe('failed')
        ->and($backup->error_message)->not->toBeNull()
        ->and($backup->storage_path)->toBeNull()
        ->and(implode("\n", $commands))->not->toContain('PRAGMA integrity_check')
        ->and(end($commands))->toBe("rm -rf '/tmp/flitops-sqlite-backup-{$backup->id}'");
});

it('marks the backup failed when the integrity check fails', function () {
    $commands = [];
    $connection = fakeSqliteBackupConnection($commands, fn (string $cmd) => str_contains($cmd, 'PRAGMA integrity_check'));

    $driver = mockSqliteBackupDriver();
    $driver->shouldReceive('delete')->once();
    bindSqliteBackupServices($connection, $driver);

    $backup = Backup::factory()->forSite($this->site)->create([
        'storage_provider_id' => $this->storageProvider->id,
    ]);

    expect(fn () => app(RunSqliteBackup::class)->execute($backup))
        ->toThrow(SshCommandException::class);

    $backup->refresh();
    expect($backup->status)->toBe('failed')
        ->and($backup->verified_at)->toBeNull();
});

it('prunes only this sites completed backups beyond the retention count', function () {
    $commands = [];
    $driver = mockSqliteBackupDriver();
    $driver->shouldReceive('delete')->times(2);
    bindSqliteBackupServices(fakeSqliteBackupConnection($commands), $driver);

    BackupSchedule::factory()->forSite($this->site)->create([
        'storage_provider_id' => $this->storageProvider->id,
        'retention_count' => 2,
    ]);

    for ($i = 3; $i >= 1; $i--) {
        Backup::factory()->forSite($this->site)->completed()->create([
            'storage_provider_id' => $this->storageProvider->id,
            'finished_at' => now()->subDays($i),
        ]);
    }

    $serverDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
    Backup::factory()->completed()->create([
        'server_database_id' => $serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'finished_at' => now()->subDays(10),
    ]);

    $newBackup = Backup::factory()->forSite($this->site)->create([
        'storage_provider_id' => $this->storageProvider->id,
    ]);

    app(RunSqliteBackup::class)->execute($newBackup);

    expect($this->site->backups()->count())->toBe(2)
        ->and($serverDatabase->backups()->count())->toBe(1);
});
