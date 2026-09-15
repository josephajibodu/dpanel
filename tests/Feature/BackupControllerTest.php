<?php

use App\Actions\Backups\RestoreDatabaseBackup;
use App\Actions\Backups\RunDatabaseBackup;
use App\Enums\ServerStatus;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
    $this->serverDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
    $this->storageProvider = StorageProvider::factory()->forTeam($this->team)->create();
    $this->basePath = "/{$this->team->slug}/servers/{$this->server->id}/databases/{$this->serverDatabase->id}/backups";
});

describe('index', function () {
    it('shows the backups page for a database', function () {
        Backup::factory()->completed()->create([
            'server_database_id' => $this->serverDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $response = $this->actingAs($this->user)->get($this->basePath);

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('servers/databases/backups/index')
                ->has('backups.data', 1)
                ->has('storageProviders')
            );
    });

    it('denies access to another teams database', function () {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->get($this->basePath);

        $response->assertForbidden();
    });
});

describe('store', function () {
    it('requires a schedule to exist before running a manual backup', function () {
        $response = $this->actingAs($this->user)->post($this->basePath);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    });

    it('dispatches a backup when a schedule exists', function () {
        BackupSchedule::factory()->create([
            'server_database_id' => $this->serverDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $actionMock = Mockery::mock(RunDatabaseBackup::class);
        $actionMock->shouldReceive('trigger')->once()->andReturn(
            Backup::factory()->make(['id' => 1])
        );
        $this->app->instance(RunDatabaseBackup::class, $actionMock);

        $response = $this->actingAs($this->user)->post($this->basePath);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    });
});

describe('destroy', function () {
    it('deletes a backup row and its stored object', function () {
        $backup = Backup::factory()->completed()->create([
            'server_database_id' => $this->serverDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $storageManager = Mockery::mock(StorageProviderManager::class);
        $driver = Mockery::mock(\App\Services\Storage\CloudflareR2Driver::class);
        $driver->shouldReceive('delete')->once()->with($backup->storage_path);
        $storageManager->shouldReceive('forAccount')->andReturn($driver);
        $this->app->instance(StorageProviderManager::class, $storageManager);

        $response = $this->actingAs($this->user)
            ->delete("{$this->basePath}/{$backup->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    });

    it('404s when the backup belongs to a different database', function () {
        $otherDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
        $backup = Backup::factory()->completed()->create([
            'server_database_id' => $otherDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("{$this->basePath}/{$backup->id}");

        $response->assertNotFound();
    });
});

describe('restore', function () {
    it('refuses to restore a backup that is not completed', function () {
        $backup = Backup::factory()->create([
            'server_database_id' => $this->serverDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
            'status' => 'failed',
        ]);

        $response = $this->actingAs($this->user)
            ->post("{$this->basePath}/{$backup->id}/restore");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    });

    it('triggers a restore for a completed backup', function () {
        $backup = Backup::factory()->completed()->create([
            'server_database_id' => $this->serverDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $actionMock = Mockery::mock(RestoreDatabaseBackup::class);
        $actionMock->shouldReceive('trigger')->once();
        $this->app->instance(RestoreDatabaseBackup::class, $actionMock);

        $response = $this->actingAs($this->user)
            ->post("{$this->basePath}/{$backup->id}/restore");

        $response->assertRedirect();
        $response->assertSessionHas('success');
    });
});

describe('download', function () {
    it('redirects to a temporary signed url', function () {
        $backup = Backup::factory()->completed()->create([
            'server_database_id' => $this->serverDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $storageManager = Mockery::mock(StorageProviderManager::class);
        $driver = Mockery::mock(\App\Services\Storage\CloudflareR2Driver::class);
        $driver->shouldReceive('temporaryUrl')->once()->andReturn('https://example.com/signed-url');
        $storageManager->shouldReceive('forAccount')->andReturn($driver);
        $this->app->instance(StorageProviderManager::class, $storageManager);

        $response = $this->actingAs($this->user)
            ->get("{$this->basePath}/{$backup->id}/download");

        $response->assertRedirect('https://example.com/signed-url');
    });
});
