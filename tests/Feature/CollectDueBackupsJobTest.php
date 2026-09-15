<?php

use App\Actions\Backups\RunDatabaseBackup;
use App\Jobs\CollectDueBackupsJob;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('dispatches a backup only for enabled, due schedules', function () {
    $server = Server::factory()->create();
    $dueDatabase = ServerDatabase::factory()->create(['server_id' => $server->id]);
    $notDueDatabase = ServerDatabase::factory()->create(['server_id' => $server->id]);
    $disabledDatabase = ServerDatabase::factory()->create(['server_id' => $server->id]);
    $storageProvider = StorageProvider::factory()->create();

    $dueSchedule = BackupSchedule::factory()->due()->create([
        'server_database_id' => $dueDatabase->id,
        'storage_provider_id' => $storageProvider->id,
    ]);
    BackupSchedule::factory()->enabled()->create([
        'server_database_id' => $notDueDatabase->id,
        'storage_provider_id' => $storageProvider->id,
        'next_run_at' => now()->addDay(),
    ]);
    BackupSchedule::factory()->create([
        'server_database_id' => $disabledDatabase->id,
        'storage_provider_id' => $storageProvider->id,
        'enabled' => false,
    ]);

    $actionMock = Mockery::mock(RunDatabaseBackup::class);
    $actionMock->shouldReceive('trigger')
        ->once()
        ->with(
            Mockery::on(fn ($db) => $db->id === $dueDatabase->id),
            Mockery::on(fn ($sp) => $sp->id === $storageProvider->id),
            'scheduled',
        );
    app()->instance(RunDatabaseBackup::class, $actionMock);

    app(CollectDueBackupsJob::class)->handle(app(RunDatabaseBackup::class));

    $dueSchedule->refresh();
    expect($dueSchedule->next_run_at->isFuture())->toBeTrue();
});

it('does not dispatch twice for the same due schedule', function () {
    $server = Server::factory()->create();
    $serverDatabase = ServerDatabase::factory()->create(['server_id' => $server->id]);
    $storageProvider = StorageProvider::factory()->create();

    BackupSchedule::factory()->due()->create([
        'server_database_id' => $serverDatabase->id,
        'storage_provider_id' => $storageProvider->id,
    ]);

    $actionMock = Mockery::mock(RunDatabaseBackup::class);
    $actionMock->shouldReceive('trigger')->once();
    app()->instance(RunDatabaseBackup::class, $actionMock);

    $job = app(CollectDueBackupsJob::class);
    $job->handle(app(RunDatabaseBackup::class));
    // A second tick right after should find nothing due anymore.
    $job->handle(app(RunDatabaseBackup::class));
});
