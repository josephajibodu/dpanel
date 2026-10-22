<?php

use App\Actions\Backups\RestoreDatabaseBackup;
use App\Actions\Backups\RunDatabaseBackup;
use App\Exceptions\BackupFailedException;
use App\Exceptions\SshCommandException;
use App\Exceptions\SshConnectionException;
use App\Jobs\RestoreBackupJob;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Notifications\DatabaseBackupFailed;
use App\Services\Ssh\SshService;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use App\Support\BackupFailureMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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
    $this->storageProvider = StorageProvider::factory()->create([
        'name' => 'Primary R2',
        'credentials' => [
            'account_id' => 'acct',
            'access_key_id' => 'AKIDSTORAGEKEY',
            'secret_access_key' => 'STORAGESECRETVALUE',
            'bucket' => 'my-bucket',
        ],
    ]);
    $this->backup = Backup::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'status' => 'dumping',
    ]);

    $this->failedUpload = fn (string $stderr) => new SshCommandException(
        command: "bash -c 'mysqldump --password=rootsecret | AWS_ACCESS_KEY_ID=AKIDSTORAGEKEY AWS_SECRET_ACCESS_KEY=STORAGESECRETVALUE aws s3 cp -'",
        exitCode: 1,
        output: '',
        stderr: $stderr,
    );
});

it('names failures the user can fix', function (string $stderr, string $expected) {
    $message = BackupFailureMessage::for(($this->failedUpload)($stderr), $this->backup);

    expect($message)->toContain($expected);
})->with([
    'r2 unauthorized' => ['upload failed: An error occurred (Unauthorized) when calling the PutObject operation: Unauthorized', "Cloudflare R2 didn't accept the access key"],
    's3 access denied' => ['An error occurred (AccessDenied) when calling the PutObject operation: Access Denied', "didn't accept the access key"],
    'invalid key' => ['An error occurred (InvalidAccessKeyId) when calling the PutObject operation', "didn't accept the access key"],
    'missing bucket' => ['An error occurred (NoSuchBucket) when calling the PutObject operation', 'The bucket set on the "Primary R2" storage provider doesn\'t exist.'],
    'endpoint unreachable' => ['Could not connect to the endpoint URL: "https://example.r2.cloudflarestorage.com"', "The server couldn't reach Cloudflare R2"],
    'disk full' => ['gzip: stdout: No space left on device', 'ran out of disk space during the backup'],
    'mysql database gone' => ["mysqldump: Got error: 1049: Unknown database 'app_production'", 'The database "app_production" no longer exists'],
    'postgres database gone' => ['pg_dump: error: database "app_production" does not exist', 'The database "app_production" no longer exists'],
]);

it('tells the user when the server cannot be reached over SSH', function () {
    $message = BackupFailureMessage::for(new SshConnectionException('SSH connection failed: Network is unreachable'), $this->backup);

    expect($message)->toBe("Couldn't connect to the server over SSH. Make sure it's online and reachable, then try again.");
});

it('passes a user-facing backup failure through unchanged', function () {
    $message = BackupFailureMessage::for(new BackupFailedException('The server is not connected.'), $this->backup);

    expect($message)->toBe('The server is not connected.');
});

it('reports a job timeout', function () {
    $message = BackupFailureMessage::for(new TimeoutExceededException('timed out'), $this->backup, 'restore');

    expect($message)->toBe("The restore didn't finish within an hour and was stopped.");
});

it('shows a generic message for failures on our end, without any command or secret', function () {
    $message = BackupFailureMessage::for(($this->failedUpload)('ERROR 1064 (42000): You have an error in your SQL syntax'), $this->backup);

    expect($message)->toStartWith('Something went wrong on our end while running this backup.')
        ->not->toContain('mysqldump')
        ->not->toContain('STORAGESECRETVALUE');
});

it('words the generic message for a restore', function () {
    $message = BackupFailureMessage::for(new RuntimeException('No database password credential'), $this->backup, 'restore');

    expect($message)->toStartWith('Something went wrong on our end while restoring this backup.');
});

it('stores the safe message, never the raw command, when a backup upload is rejected', function () {
    $connection = mockDatabaseConnection(onExec: function (string $command) {
        if (str_contains($command, 'aws s3 cp -')) {
            throw ($this->failedUpload)('upload failed: An error occurred (Unauthorized) when calling the PutObject operation: Unauthorized');
        }
    });

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('my-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = AKIDSTORAGEKEY\naws_secret_access_key = STORAGESECRETVALUE\n");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");
    $driver->shouldReceive('delete');
    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);

    expect(fn () => app(RunDatabaseBackup::class)->execute($this->backup))
        ->toThrow(SshCommandException::class);

    $this->backup->refresh();
    expect($this->backup->status)->toBe('failed')
        ->and($this->backup->error_message)->toStartWith("Cloudflare R2 didn't accept the access key")
        ->and($this->backup->error_message)->not->toContain('STORAGESECRETVALUE');
});

it('marks a backup failed and notifies when it fails before the action can handle it', function () {
    Notification::fake();

    (new RunBackupJob($this->backup))->failed(new SshConnectionException('SSH connection failed: Network is unreachable'));

    $this->backup->refresh();
    expect($this->backup->status)->toBe('failed')
        ->and($this->backup->finished_at)->not->toBeNull()
        ->and($this->backup->error_message)->toStartWith("Couldn't connect to the server over SSH.");

    Notification::assertSentTo(
        $this->server->team->owner,
        DatabaseBackupFailed::class,
        fn (DatabaseBackupFailed $notification) => $notification->backup->error_message === $this->backup->error_message,
    );
});

it('keeps the message the action already recorded', function () {
    Notification::fake();
    $this->backup->update(['status' => 'failed', 'error_message' => 'Recorded by the action.']);

    (new RunBackupJob($this->backup))->failed(new RuntimeException('boom'));

    expect($this->backup->fresh()->error_message)->toBe('Recorded by the action.');
});

it('logs the full error with server and storage secrets redacted', function () {
    Notification::fake();
    Log::spy();

    (new RunBackupJob($this->backup))->failed(($this->failedUpload)('An error occurred (Unauthorized) when calling the PutObject operation'));

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
        return $context['backup_id'] === $this->backup->id
            && $context['status'] === 'dumping'
            && $context['exception'] === SshCommandException::class
            && str_contains($context['message'], 'An error occurred (Unauthorized)')
            && str_contains($context['message'], 'AWS_SECRET_ACCESS_KEY=[redacted]')
            && ! str_contains($context['message'], 'AKIDSTORAGEKEY')
            && ! str_contains($context['message'], 'STORAGESECRETVALUE')
            && ! str_contains($context['message'], 'rootsecret');
    })->once();
});

it('marks a running restore failed when the restore job fails early', function () {
    $this->backup->update(['status' => 'completed', 'restore_status' => 'running']);

    (new RestoreBackupJob($this->backup))->failed(new SshConnectionException('SSH connection failed'));

    $this->backup->refresh();
    expect($this->backup->restore_status)->toBe('failed')
        ->and($this->backup->restore_error)->toStartWith("Couldn't connect to the server over SSH.");
});

it('leaves a finished restore alone when the restore job fails', function () {
    $this->backup->update(['status' => 'completed', 'restore_status' => 'completed']);

    (new RestoreBackupJob($this->backup))->failed(new RuntimeException('late failure'));

    expect($this->backup->fresh()->restore_status)->toBe('completed');
});

it('fails a backup with a plain message when the storage key no longer works, before touching the server', function () {
    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('validateCredentials')->once()->andReturn(false);
    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');
    app()->instance(SshService::class, $sshService);

    $this->storageProvider->update(['is_valid' => true]);

    expect(fn () => app()->call([new RunBackupJob($this->backup), 'handle']))
        ->toThrow(BackupFailedException::class, "Cloudflare R2 didn't accept the access key on the \"Primary R2\" storage provider. The key has most likely expired or been revoked");

    expect($this->storageProvider->fresh()->is_valid)->toBeFalse();
});

it('records the storage key check message on the backup and in the email', function () {
    Notification::fake();
    $exception = new BackupFailedException('Cloudflare R2 didn\'t accept the access key on the "Primary R2" storage provider.');

    (new RunBackupJob($this->backup))->failed($exception);

    expect($this->backup->fresh()->error_message)->toBe($exception->getMessage());
    Notification::assertSentTo($this->server->team->owner, DatabaseBackupFailed::class);
});

it('runs the backup when the storage key works', function () {
    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('validateCredentials')->once()->andReturn(true);
    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);

    $action = Mockery::mock(RunDatabaseBackup::class);
    $action->shouldReceive('execute')->once()->with(Mockery::on(fn (Backup $backup) => $backup->is($this->backup)));
    app()->instance(RunDatabaseBackup::class, $action);

    app()->call([new RunBackupJob($this->backup), 'handle']);

    expect($this->storageProvider->fresh())
        ->is_valid->toBeTrue()
        ->validated_at->not->toBeNull();
});

it('checks the storage key before a restore', function () {
    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('validateCredentials')->once()->andReturn(false);
    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);

    $restore = Mockery::mock(RestoreDatabaseBackup::class);
    $restore->shouldNotReceive('execute');
    app()->instance(RestoreDatabaseBackup::class, $restore);

    expect(fn () => app()->call([new RestoreBackupJob($this->backup), 'handle']))
        ->toThrow(BackupFailedException::class, 'then run the restore again.');
});
