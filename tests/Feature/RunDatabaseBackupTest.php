<?php

use App\Actions\Backups\RunDatabaseBackup;
use App\Exceptions\SshCommandException;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Notifications\DatabaseBackupFailed;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function mockBackupDriver(): CloudflareR2Driver
{
    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
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
    $connection = mockDatabaseConnection();

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
    $connection = mockDatabaseConnection(onExec: function (string $command) {
        if (str_contains($command, 'mysqldump')) {
            throw new SshCommandException('dump-command', 1, '', 'mysqldump: Access denied');
        }
    });

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
    $connection = mockDatabaseConnection(onExec: function (string $command) {
        // The verify step downloads the dump and imports it into the scratch database.
        if (str_contains($command, 'gunzip -c')) {
            throw new SshCommandException('verify-import', 1, '', 'ERROR 1064: syntax error');
        }
    });

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
    $connection = mockDatabaseConnection();

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

it('notifies the team owner when a scheduled backup without a user fails', function () {
    Notification::fake();

    $backup = Backup::factory()->scheduled()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
    ]);

    (new RunBackupJob($backup))->failed(new RuntimeException('Dump failed.'));

    $owner = $this->server->team->owner;
    expect($backup->user_id)->toBeNull()
        ->and($owner->is($this->server->user))->toBeFalse();

    Notification::assertSentTo($owner, DatabaseBackupFailed::class, fn (DatabaseBackupFailed $notification) => $notification->backup->is($backup));
    Notification::assertNotSentTo($this->server->user, DatabaseBackupFailed::class);
});
