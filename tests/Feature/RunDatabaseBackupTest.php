<?php

use App\Actions\Backups\RunDatabaseBackup;
use App\Exceptions\SshCommandException;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockBackupDriver(): CloudflareR2Driver
{
    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('envPrefix')->andReturn("AWS_ACCESS_KEY_ID='x' AWS_SECRET_ACCESS_KEY='y'");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");
    $driver->shouldReceive('size')->andReturn(2048);

    return $driver;
}

function bindMockedBackupServices(SshConnection $connection, $driver): void
{
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);
}

beforeEach(function () {
    $this->server = Server::factory()->create(['database_type' => 'mysql']);
    ServerCredential::create([
        'server_id' => $this->server->id,
        'type' => 'database_password',
        'value' => 'rootsecret',
    ]);
    $this->serverDatabase = ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'app_production',
    ]);
    $this->storageProvider = StorageProvider::factory()->create();
});

it('completes a backup: dumps, verifies, uploads, and records the result', function () {
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('disconnect')->andReturn(null);

    $driver = mockBackupDriver();
    bindMockedBackupServices($connection, $driver);

    $backup = Backup::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'status' => 'pending',
    ]);

    app(RunDatabaseBackup::class)->execute($backup);

    $backup->refresh();
    expect($backup->status)->toBe('completed')
        ->and($backup->storage_path)->not->toBeNull()
        ->and($backup->size_bytes)->toBe(2048)
        ->and($backup->verified_at)->not->toBeNull()
        ->and($backup->finished_at)->not->toBeNull();
});

it('marks the backup failed when the dump itself fails, without uploading', function () {
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $callCount = 0;
    $connection->shouldReceive('exec')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        // First call is the "ensure aws cli" check; second is the dump+upload pipeline.
        if ($callCount === 2) {
            throw new SshCommandException('dump-command', 1, '', 'mysqldump: Access denied');
        }

        return '';
    });
    $connection->shouldReceive('disconnect')->andReturn(null);

    $driver = mockBackupDriver();
    $driver->shouldReceive('delete')->once();
    bindMockedBackupServices($connection, $driver);

    $backup = Backup::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'status' => 'pending',
    ]);

    expect(fn () => app(RunDatabaseBackup::class)->execute($backup))
        ->toThrow(SshCommandException::class);

    $backup->refresh();
    expect($backup->status)->toBe('failed')
        ->and($backup->error_message)->not->toBeNull()
        ->and($backup->storage_path)->toBeNull();
});

it('marks the backup failed and deletes the uploaded object when verification fails', function () {
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $callCount = 0;
    $connection->shouldReceive('exec')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        // 1: ensure aws cli, 2: dump+upload, 3: drop scratch (pre), 4: create
        // scratch, 5: the verify import pipeline — fail here.
        if ($callCount === 5) {
            throw new SshCommandException('verify-import', 1, '', 'ERROR 1064: syntax error');
        }

        return '';
    });
    $connection->shouldReceive('disconnect')->andReturn(null);

    $driver = mockBackupDriver();
    $driver->shouldReceive('delete')->once();
    bindMockedBackupServices($connection, $driver);

    $backup = Backup::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'status' => 'pending',
    ]);

    expect(fn () => app(RunDatabaseBackup::class)->execute($backup))
        ->toThrow(SshCommandException::class);

    $backup->refresh();
    expect($backup->status)->toBe('failed');
});

it('prunes completed backups beyond the schedule retention count', function () {
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('disconnect')->andReturn(null);

    $driver = mockBackupDriver();
    $driver->shouldReceive('delete')->times(2); // the 2 pruned backups
    bindMockedBackupServices($connection, $driver);

    \App\Models\BackupSchedule::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'retention_count' => 2,
    ]);

    // 3 pre-existing completed backups, oldest first.
    for ($i = 3; $i >= 1; $i--) {
        Backup::factory()->completed()->create([
            'server_database_id' => $this->serverDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
            'finished_at' => now()->subDays($i),
        ]);
    }

    $newBackup = Backup::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'status' => 'pending',
    ]);

    app(RunDatabaseBackup::class)->execute($newBackup);

    // Retention 2 means only the 2 most recent completed backups survive
    // (the new one plus the newest of the 3 pre-existing ones); the 2
    // oldest pre-existing ones are pruned.
    expect($this->serverDatabase->backups()->count())->toBe(2);
});
