<?php

use App\Actions\Backups\RestoreDatabaseBackup;
use App\Actions\Backups\RunDatabaseBackup;
use App\Actions\Backups\RunSqliteBackup;
use App\Exceptions\SshCommandException;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\StorageProvider;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const BACKUP_ROOT_PASSWORD = 'BackupRootSecret0123456789abcdef';
const STORAGE_ACCESS_KEY = 'AKIDSTORAGEACCESSKEY';
const STORAGE_SECRET_KEY = 'StorageSecretKeyThatMustNeverLeak0123456';

/**
 * Bind a connection plus a real R2 driver (with only its network calls
 * stubbed), so the credentials file under test is the one the app builds.
 */
function bindBackupServices(SshConnection $connection): void
{
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class)->makePartial();
    $driver->setCredentials([
        'account_id' => 'acc123',
        'access_key_id' => STORAGE_ACCESS_KEY,
        'secret_access_key' => STORAGE_SECRET_KEY,
        'bucket' => 'backups',
    ]);
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");
    $driver->shouldReceive('size')->andReturn(1024);
    $driver->shouldReceive('delete');

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);
}

function databaseBackupFor(string $databaseType, array $attributes = []): Backup
{
    $server = Server::factory()->create(['database_type' => $databaseType]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => BACKUP_ROOT_PASSWORD]);
    $serverDatabase = ServerDatabase::factory()->create(['server_id' => $server->id, 'name' => 'app_production']);

    return Backup::factory()->create([
        'server_database_id' => $serverDatabase->id,
        'storage_provider_id' => StorageProvider::factory()->create()->id,
        'status' => 'pending',
        ...$attributes,
    ]);
}

function expectNoSecretsIn(array $commands): void
{
    expect($commands)->not->toBeEmpty();

    foreach ($commands as $command) {
        expect($command)
            ->not->toContain(BACKUP_ROOT_PASSWORD)
            ->not->toContain(STORAGE_ACCESS_KEY)
            ->not->toContain(STORAGE_SECRET_KEY)
            ->not->toContain('--password')
            ->not->toContain('PGPASSWORD')
            ->not->toContain('AWS_SECRET_ACCESS_KEY');
    }
}

it('keeps database and storage secrets out of every backup command', function (string $databaseType) {
    $backup = databaseBackupFor($databaseType);

    $uploads = [];
    $commands = [];
    bindBackupServices(mockDatabaseConnection($uploads, $commands));

    app(RunDatabaseBackup::class)->execute($backup);

    expect($backup->fresh()->status)->toBe('completed');
    expectNoSecretsIn($commands);
})->with(['mysql', 'mariadb', 'postgresql']);

it('hands mysqldump and the aws cli their credentials as private files', function () {
    $backup = databaseBackupFor('mysql');

    $uploads = [];
    $commands = [];
    bindBackupServices(mockDatabaseConnection($uploads, $commands));

    app(RunDatabaseBackup::class)->execute($backup);

    expect($uploads['/tmp/tmp.fleetops/client.cnf'])->toBe("[client]\nuser=root\npassword=\"".BACKUP_ROOT_PASSWORD."\"\n")
        ->and($uploads['/tmp/tmp.fleetops/aws-credentials'])->toBe(
            "[default]\naws_access_key_id = ".STORAGE_ACCESS_KEY."\naws_secret_access_key = ".STORAGE_SECRET_KEY."\n"
        );

    $dump = collect($commands)->first(fn (string $command) => str_contains($command, 'mysqldump'));

    expect($dump)
        ->toContain("sudo mysqldump --defaults-extra-file='\\''/tmp/tmp.fleetops/client.cnf'\\''")
        ->toContain("AWS_SHARED_CREDENTIALS_FILE='\\''/tmp/tmp.fleetops/aws-credentials'\\'' aws s3 cp -");

    expect(end($commands))->toBe("rm -rf '/tmp/tmp.fleetops'");
});

it('removes the private credentials directory when the backup fails', function () {
    $backup = databaseBackupFor('mysql');

    $uploads = [];
    $commands = [];
    bindBackupServices(mockDatabaseConnection($uploads, $commands, function (string $command) {
        if (str_contains($command, 'mysqldump')) {
            throw new SshCommandException('dump', 1, '', 'mysqldump: Access denied');
        }
    }));

    expect(fn () => app(RunDatabaseBackup::class)->execute($backup))->toThrow(SshCommandException::class);

    expect($backup->fresh()->status)->toBe('failed')
        ->and(end($commands))->toBe("rm -rf '/tmp/tmp.fleetops'");
});

it('keeps database and storage secrets out of the restore command', function (string $databaseType) {
    $backup = databaseBackupFor($databaseType, ['status' => 'completed', 'storage_path' => 'backups/1/dump.sql.gz']);

    $uploads = [];
    $commands = [];
    bindBackupServices(mockDatabaseConnection($uploads, $commands));

    app(RestoreDatabaseBackup::class)->execute($backup);

    expect($backup->fresh()->restore_status)->toBe('completed');
    expectNoSecretsIn($commands);
    expect(end($commands))->toBe("rm -rf '/tmp/tmp.fleetops'");
})->with(['mysql', 'postgresql']);

it('keeps storage secrets out of sqlite site backup commands', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->forServer($server)->create(['server_database_id' => null]);
    $backup = Backup::factory()->create([
        'server_database_id' => null,
        'site_id' => $site->id,
        'storage_provider_id' => StorageProvider::factory()->create()->id,
        'status' => 'pending',
    ]);

    $uploads = [];
    $commands = [];
    bindBackupServices(mockDatabaseConnection($uploads, $commands));

    app(RunSqliteBackup::class)->execute($backup);

    expect($backup->fresh()->status)->toBe('completed')
        ->and($uploads)->toHaveKey('/tmp/tmp.fleetops/aws-credentials');
    expectNoSecretsIn($commands);
});
