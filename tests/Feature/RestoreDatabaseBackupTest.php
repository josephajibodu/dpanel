<?php

use App\Actions\Backups\RestoreDatabaseBackup;
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

it('restores a completed backup in place', function () {
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->once()->andReturn('');
    $connection->shouldReceive('disconnect')->andReturn(null);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('envPrefix')->andReturn("AWS_ACCESS_KEY_ID='x' AWS_SECRET_ACCESS_KEY='y'");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);

    $backup = Backup::factory()->completed()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
    ]);

    app(RestoreDatabaseBackup::class)->execute($backup);

    $backup->refresh();
    expect($backup->restore_status)->toBe('completed')
        ->and($backup->restored_at)->not->toBeNull()
        ->and($backup->restore_error)->toBeNull();
});

it('marks the restore failed when the remote restore command fails', function () {
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->once()->andThrow(
        new SshCommandException('restore-command', 1, '', 'ERROR: connection refused'),
    );
    $connection->shouldReceive('disconnect')->andReturn(null);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('envPrefix')->andReturn("AWS_ACCESS_KEY_ID='x' AWS_SECRET_ACCESS_KEY='y'");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);

    $backup = Backup::factory()->completed()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
    ]);

    expect(fn () => app(RestoreDatabaseBackup::class)->execute($backup))
        ->toThrow(SshCommandException::class);

    $backup->refresh();
    expect($backup->restore_status)->toBe('failed')
        ->and($backup->restore_error)->not->toBeNull();
});

it('refuses to restore a backup with no stored file', function () {
    $backup = Backup::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'status' => 'pending',
        'storage_path' => null,
    ]);

    expect(fn () => app(RestoreDatabaseBackup::class)->execute($backup))
        ->toThrow(RuntimeException::class);
});
