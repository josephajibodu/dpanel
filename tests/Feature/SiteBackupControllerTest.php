<?php

use App\Actions\Backups\RunSqliteBackup;
use App\Enums\ServerStatus;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Storage\CloudflareR2Driver;
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
    $this->site = Site::factory()->forServer($this->server)->create(['server_database_id' => null]);
    $this->storageProvider = StorageProvider::factory()->forTeam($this->team)->create();
    $this->sitePath = "/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}";
    $this->basePath = "{$this->sitePath}/backups";
});

describe('index', function () {
    it('shows the backups page for a sqlite site', function () {
        Backup::factory()->forSite($this->site)->completed()->create([
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $response = $this->actingAs($this->user)->get($this->basePath);

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/backups/index')
                ->where('usesSqlite', true)
                ->where('serverDatabase', null)
                ->has('backups.data', 1)
                ->has('storageProviders')
            );
    });

    it('points sites with a server database at that databases backups', function () {
        $serverDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
        $this->site->update(['server_database_id' => $serverDatabase->id]);

        $response = $this->actingAs($this->user)->get($this->basePath);

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('usesSqlite', false)
                ->where('serverDatabase.id', $serverDatabase->id)
            );
    });

    it('denies access to another teams site', function () {
        $response = $this->actingAs(User::factory()->create())->get($this->basePath);

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
        BackupSchedule::factory()->forSite($this->site)->create([
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $actionMock = Mockery::mock(RunSqliteBackup::class);
        $actionMock->shouldReceive('trigger')
            ->once()
            ->with(
                Mockery::on(fn ($site) => $site->id === $this->site->id),
                Mockery::on(fn ($sp) => $sp->id === $this->storageProvider->id),
                'manual',
                Mockery::on(fn ($user) => $user->id === $this->user->id),
            )
            ->andReturn(Backup::factory()->make(['id' => 1]));
        $this->app->instance(RunSqliteBackup::class, $actionMock);

        $response = $this->actingAs($this->user)->post($this->basePath);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    });

    it('refuses to back up a site that uses a server database', function () {
        $serverDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
        $this->site->update(['server_database_id' => $serverDatabase->id]);
        BackupSchedule::factory()->forSite($this->site)->create([
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $actionMock = Mockery::mock(RunSqliteBackup::class);
        $actionMock->shouldNotReceive('trigger');
        $this->app->instance(RunSqliteBackup::class, $actionMock);

        $response = $this->actingAs($this->user)->post($this->basePath);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    });
});

describe('schedule', function () {
    it('creates a backup schedule for a sqlite site', function () {
        $response = $this->actingAs($this->user)->put("{$this->sitePath}/backup-schedule", [
            'storage_provider_id' => $this->storageProvider->id,
            'frequency' => 'hourly',
            'retention_count' => 3,
            'enabled' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $schedule = $this->site->backupSchedule()->first();
        expect($schedule)->not->toBeNull()
            ->and($schedule->server_database_id)->toBeNull()
            ->and($schedule->frequency)->toBe('hourly')
            ->and($schedule->retention_count)->toBe(3)
            ->and($schedule->next_run_at)->not->toBeNull();
    });

    it('refuses a schedule for a site that uses a server database', function () {
        $serverDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
        $this->site->update(['server_database_id' => $serverDatabase->id]);

        $response = $this->actingAs($this->user)->put("{$this->sitePath}/backup-schedule", [
            'storage_provider_id' => $this->storageProvider->id,
            'frequency' => 'daily',
            'retention_count' => 7,
            'enabled' => true,
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('backup_schedules', 0);
    });

    it('rejects a storage provider from another team', function () {
        $otherProvider = StorageProvider::factory()->create();

        $response = $this->actingAs($this->user)->put("{$this->sitePath}/backup-schedule", [
            'storage_provider_id' => $otherProvider->id,
            'frequency' => 'daily',
            'retention_count' => 7,
            'enabled' => true,
        ]);

        $response->assertSessionHasErrors('storage_provider_id');
    });
});

describe('destroy', function () {
    it('deletes a backup row and its stored object', function () {
        $backup = Backup::factory()->forSite($this->site)->completed()->create([
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $driver = Mockery::mock(CloudflareR2Driver::class);
        $driver->shouldReceive('delete')->once()->with($backup->storage_path);
        $storageManager = Mockery::mock(StorageProviderManager::class);
        $storageManager->shouldReceive('forAccount')->andReturn($driver);
        $this->app->instance(StorageProviderManager::class, $storageManager);

        $response = $this->actingAs($this->user)->delete("{$this->basePath}/{$backup->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    });

    it('404s when the backup belongs to a different site', function () {
        $otherSite = Site::factory()->forServer($this->server)->create();
        $backup = Backup::factory()->forSite($otherSite)->completed()->create([
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $response = $this->actingAs($this->user)->delete("{$this->basePath}/{$backup->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('backups', ['id' => $backup->id]);
    });

    it('404s for a server database backup', function () {
        $serverDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
        $backup = Backup::factory()->completed()->create([
            'server_database_id' => $serverDatabase->id,
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $response = $this->actingAs($this->user)->delete("{$this->basePath}/{$backup->id}");

        $response->assertNotFound();
    });
});

describe('download', function () {
    it('redirects to a temporary signed url', function () {
        $backup = Backup::factory()->forSite($this->site)->completed()->create([
            'storage_provider_id' => $this->storageProvider->id,
        ]);

        $driver = Mockery::mock(CloudflareR2Driver::class);
        $driver->shouldReceive('temporaryUrl')->once()->andReturn('https://example.com/signed-url');
        $storageManager = Mockery::mock(StorageProviderManager::class);
        $storageManager->shouldReceive('forAccount')->andReturn($driver);
        $this->app->instance(StorageProviderManager::class, $storageManager);

        $response = $this->actingAs($this->user)->get("{$this->basePath}/{$backup->id}/download");

        $response->assertRedirect('https://example.com/signed-url');
    });
});
