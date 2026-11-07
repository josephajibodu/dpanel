<?php

use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
    $this->storageProvider = StorageProvider::factory()->forTeam($this->team)->create();
});

it('shows the server database the site uses, with that databases schedule and backups', function () {
    $databaseServer = Server::factory()->forTeam($this->team)->database()->create();
    $database = ServerDatabase::factory()->create(['server_id' => $databaseServer->id, 'name' => 'shop']);
    $site = Site::factory()->forServer($this->server)->create(['server_database_id' => $database->id]);
    $schedule = BackupSchedule::factory()->create([
        'server_database_id' => $database->id,
        'storage_provider_id' => $this->storageProvider->id,
    ]);
    $databaseBackup = Backup::factory()->completed()->create([
        'server_database_id' => $database->id,
        'storage_provider_id' => $this->storageProvider->id,
    ]);
    Backup::factory()->forSite($site)->completed()->create(['storage_provider_id' => $this->storageProvider->id]);

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/database");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/database/show')
            ->where('usesSqlite', false)
            ->where('sqlitePath', null)
            ->where('databaseConnection.server.id', $databaseServer->id)
            ->where('databaseConnection.database.name', 'shop')
            ->where('schedule.data.id', $schedule->id)
            ->has('backups.data', 1)
            ->where('backups.data.0.id', $databaseBackup->id)
            ->has('storageProviders.data', 1)
        );
});

it('shows the sqlite file and the sites own schedule and backups when the site has no server database', function () {
    $site = Site::factory()->forServer($this->server)->create(['server_database_id' => null]);
    $schedule = BackupSchedule::factory()->forSite($site)->create(['storage_provider_id' => $this->storageProvider->id]);
    $siteBackup = Backup::factory()->forSite($site)->completed()->create(['storage_provider_id' => $this->storageProvider->id]);

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/database");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/database/show')
            ->where('usesSqlite', true)
            ->where('sqlitePath', fn (string $path): bool => str_ends_with($path, '/shared/database/database.sqlite'))
            ->where('databaseConnection', null)
            ->where('schedule.data.id', $schedule->id)
            ->has('backups.data', 1)
            ->where('backups.data.0.id', $siteBackup->id)
        );
});

it('refreshes the schedule and backups from the server the site database lives on', function () {
    $databaseServer = Server::factory()->forTeam($this->team)->database()->create();
    $database = ServerDatabase::factory()->create(['server_id' => $databaseServer->id]);
    $site = Site::factory()->forServer($this->server)->create(['server_database_id' => $database->id]);
    $listener = "private:server.{$databaseServer->id} backup.status.changed";

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/database");

    expect(liveProps($response))->toBe([
        'schedule' => [$listener],
        'backups' => [$listener],
    ]);
});

it('refreshes the schedule and backups from the sites own server when it has no server database', function () {
    $site = Site::factory()->forServer($this->server)->create(['server_database_id' => null]);
    $listener = "private:server.{$this->server->id} backup.status.changed";

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/database");

    expect(liveProps($response))->toBe([
        'schedule' => [$listener],
        'backups' => [$listener],
    ]);
});

it('shows no database for a site that has neither a server database nor sqlite', function () {
    $site = Site::factory()->forServer($this->server)->create([
        'project_type' => ProjectType::StaticHtml,
        'server_database_id' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/database");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('usesSqlite', false)
            ->where('sqlitePath', null)
            ->where('databaseConnection', null)
            ->where('schedule', null)
            ->has('backups.data', 0)
        );
});

it('denies access to another teams site', function () {
    $site = Site::factory()->forServer($this->server)->create();

    $response = $this->actingAs(User::factory()->create())
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/database");

    $response->assertForbidden();
});
