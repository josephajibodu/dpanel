<?php

use App\Actions\Backups\RestoreDatabaseBackup;
use App\Exceptions\BackupFailedException;
use App\Exceptions\SshCommandException;
use App\Models\Backup;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
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
    $connection = mockDatabaseConnection();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
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
    $connection = mockDatabaseConnection(onExec: function (string $command) {
        if (str_contains($command, 'gunzip -c')) {
            throw new SshCommandException('restore-command', 1, '', 'ERROR: connection refused');
        }
    });

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
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

it('loads an imported dump into the chosen database, dropping the lines that name another one', function () {
    $commands = [];
    $uploads = [];
    $connection = mockDatabaseConnection($uploads, $commands);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);

    $backup = Backup::factory()->completed()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'triggered_by' => 'import',
        'storage_path' => 'imports/1/dump.sql',
    ]);

    app(RestoreDatabaseBackup::class)->execute($backup);

    $importCommand = collect($commands)->first(fn (string $command) => str_contains($command, 'aws s3 cp'));
    expect($importCommand)
        ->toContain('gunzip -cf')
        ->toContain('CREATE DATABASE|USE ')
        ->toContain('--commands=OFF')
        ->toContain('app_production')
        ->and($backup->fresh()->restore_status)->toBe('completed');
});

it('installs the AWS CLI before downloading an imported dump', function () {
    $commands = [];
    $uploads = [];
    $connection = mockDatabaseConnection($uploads, $commands);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);

    $backup = Backup::factory()->completed()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'triggered_by' => 'import',
    ]);

    app(RestoreDatabaseBackup::class)->execute($backup);

    $installAt = collect($commands)->search(fn (string $command) => str_contains($command, 'apt-get install -y -qq awscli'));
    $downloadAt = collect($commands)->search(fn (string $command) => str_contains($command, 'aws s3 cp'));
    expect($installAt)->toBeInt()
        ->toBeLessThan($downloadAt);
});

it('shows the database error when an imported dump is rejected', function () {
    $connection = mockDatabaseConnection(onExec: function (string $command) {
        if (str_contains($command, 'aws s3 cp')) {
            throw new SshCommandException('import-command', 1, '', "ERROR 1064 (42000) at line 12: You have an error in your SQL syntax\n");
        }
    });

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);

    $backup = Backup::factory()->completed()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'triggered_by' => 'import',
    ]);

    expect(fn () => app(RestoreDatabaseBackup::class)->execute($backup))
        ->toThrow(SshCommandException::class);

    expect($backup->fresh()->restore_error)
        ->toBe('The database rejected the imported dump: ERROR 1064 (42000) at line 12: You have an error in your SQL syntax');
});

it('imports a dump into Postgres in one transaction and hands the tables to the oldest read-write user', function () {
    $this->server->update(['database_type' => 'postgresql']);
    $owner = DatabaseUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'app_owner',
        'databases' => ['app_production'],
    ]);
    DatabaseUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'app_reader',
        'permission' => 'readonly',
        'databases' => ['app_production'],
    ]);

    $commands = [];
    $uploads = [];
    $grantScripts = [];
    $connection = mockDatabaseConnection($uploads, $commands, function (string $command) use (&$uploads, &$grantScripts) {
        if (str_contains($command, 'statements.sql')) {
            $grantScripts[] = $uploads['/tmp/tmp.fleetops/statements.sql'];
        }
    });

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);

    $backup = Backup::factory()->completed()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'triggered_by' => 'import',
    ]);

    app(RestoreDatabaseBackup::class)->execute($backup);

    $importCommand = collect($commands)->first(fn (string $command) => str_contains($command, 'aws s3 cp'));
    expect($importCommand)
        ->toContain('ON_ERROR_STOP=1')
        ->toContain('prelude.sql')
        ->toContain('postlude.sql')
        ->toContain('app_production')
        ->and($uploads['/tmp/tmp.fleetops/prelude.sql'])->toMatch('/^\\\\restrict [A-Za-z0-9]{32}\nBEGIN;/')
        ->and($uploads['/tmp/tmp.fleetops/postlude.sql'])
        ->toContain("owner_name text := 'app_owner'")
        ->toEndWith("COMMIT;\n")
        ->and(implode('
', $grantScripts))
        ->toContain('GRANT ALL ON SCHEMA public TO "app_owner"')
        ->toContain('GRANT SELECT ON ALL TABLES IN SCHEMA public TO "app_reader"')
        ->and($backup->fresh()->restore_status)->toBe('completed')
        ->and($owner->exists)->toBeTrue();
});

it('refuses a Postgres import when the database has no read-write user', function () {
    $this->server->update(['database_type' => 'postgresql']);
    $connection = mockDatabaseConnection();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);

    $backup = Backup::factory()->completed()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'triggered_by' => 'import',
    ]);

    expect(fn () => app(RestoreDatabaseBackup::class)->execute($backup))
        ->toThrow(BackupFailedException::class);

    expect($backup->fresh()->restore_error)->toContain('Add a database user with read-write access to app_production');
});

it('refuses to import with a database client that cannot switch off its shell commands', function (string $databaseType, string $check) {
    $this->server->update(['database_type' => $databaseType]);
    DatabaseUser::factory()->create([
        'server_id' => $this->server->id,
        'databases' => ['app_production'],
    ]);

    $commands = [];
    $uploads = [];
    $connection = mockDatabaseConnection($uploads, $commands, function (string $command) use ($check) {
        if (str_contains($command, $check)) {
            throw new SshCommandException($command, 7, '', 'unknown option');
        }
    });

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\naws_secret_access_key = y\n");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);

    $backup = Backup::factory()->completed()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'triggered_by' => 'import',
    ]);

    expect(fn () => app(RestoreDatabaseBackup::class)->execute($backup))
        ->toThrow(BackupFailedException::class);

    expect($backup->fresh()->restore_error)->toContain('too old to load a dump from elsewhere safely')
        ->and(collect($commands)->contains(fn (string $command) => str_contains($command, 'aws s3 cp')))->toBeFalse();
})->with([
    'mysql' => ['mysql', 'mysql --commands=OFF --help'],
    'mariadb' => ['mariadb', 'mariadb --sandbox --help'],
    'postgresql' => ['postgresql', 'restrict flitopscheck'],
]);
