<?php

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->personalTeam = Team::factory()->forUser($this->owner)->create();
    $this->team = Team::factory()->create(['user_id' => $this->owner->id, 'name' => 'Acme Ops']);
    $this->owner->switchTeam($this->team);

    $this->server = Server::factory()->forTeam($this->team)->create();
    $this->storageProvider = StorageProvider::factory()->create(['team_id' => $this->team->id]);
});

/**
 * Give the team a database backup, a database backup schedule and a SQLite
 * site backup, all stored on the team's storage provider.
 */
function giveTeamBackups(Server $server, StorageProvider $storageProvider): void
{
    $database = ServerDatabase::factory()->create(['server_id' => $server->id]);
    $site = Site::factory()->forServer($server)->create(['server_database_id' => null]);

    Backup::factory()->create(['server_database_id' => $database->id, 'storage_provider_id' => $storageProvider->id]);
    BackupSchedule::factory()->create(['server_database_id' => $database->id, 'storage_provider_id' => $storageProvider->id]);
    Backup::factory()->create(['server_database_id' => null, 'site_id' => $site->id, 'storage_provider_id' => $storageProvider->id]);
}

it('deletes a team that has backups and backup schedules', function () {
    giveTeamBackups($this->server, $this->storageProvider);

    $this->actingAs($this->owner)
        ->delete(route('teams.destroy', $this->team), ['name' => 'Acme Ops'])
        ->assertRedirect(route('servers.index', $this->personalTeam));

    expect($this->team->fresh())->toBeNull()
        ->and(StorageProvider::query()->whereKey($this->storageProvider->id)->exists())->toBeFalse()
        ->and(Backup::query()->where('storage_provider_id', $this->storageProvider->id)->exists())->toBeFalse()
        ->and(BackupSchedule::query()->where('storage_provider_id', $this->storageProvider->id)->exists())->toBeFalse()
        ->and($this->owner->fresh()->current_team_id)->toBe($this->personalTeam->id);
});

it('leaves uploaded backup files alone and other teams\' backups in place', function () {
    giveTeamBackups($this->server, $this->storageProvider);

    $otherTeam = Team::factory()->create();
    $otherProvider = StorageProvider::factory()->create(['team_id' => $otherTeam->id]);
    giveTeamBackups(Server::factory()->forTeam($otherTeam)->create(), $otherProvider);

    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldNotReceive('forAccount');
    app()->instance(StorageProviderManager::class, $storageManager);

    $this->actingAs($this->owner)
        ->delete(route('teams.destroy', $this->team), ['name' => 'Acme Ops'])
        ->assertRedirect();

    expect(Backup::query()->where('storage_provider_id', $otherProvider->id)->count())->toBe(2)
        ->and(BackupSchedule::query()->where('storage_provider_id', $otherProvider->id)->count())->toBe(1);
});

it('changes nothing, including the current team, when the delete fails', function () {
    giveTeamBackups($this->server, $this->storageProvider);
    Team::deleting(fn () => throw new RuntimeException('Simulated failure'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->owner)
        ->delete(route('teams.destroy', $this->team), ['name' => 'Acme Ops']))
        ->toThrow(RuntimeException::class, 'Simulated failure');

    expect($this->team->fresh())->not->toBeNull()
        ->and(Backup::query()->where('storage_provider_id', $this->storageProvider->id)->count())->toBe(2)
        ->and(BackupSchedule::query()->where('storage_provider_id', $this->storageProvider->id)->count())->toBe(1)
        ->and($this->owner->fresh()->current_team_id)->toBe($this->team->id);
});

it('deletes a team whose members hold one of its own custom roles', function () {
    $customRole = TeamRole::query()->create(['team_id' => $this->team->id, 'key' => 'deployer', 'name' => 'Deployer']);
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['team_role_id' => $customRole->id]);
    $this->team->invitations()->create(['email' => 'new@example.com', 'team_role_id' => $customRole->id, 'token' => 'custom-invite']);

    $this->actingAs($this->owner)
        ->delete(route('teams.destroy', $this->team), ['name' => 'Acme Ops'])
        ->assertRedirect();

    expect($this->team->fresh())->toBeNull()
        ->and(TeamRole::query()->whereKey($customRole->id)->exists())->toBeFalse();
});

it('still refuses to delete a storage provider that backups use', function () {
    giveTeamBackups($this->server, $this->storageProvider);

    expect(fn () => $this->storageProvider->delete())->toThrow(QueryException::class);
});
