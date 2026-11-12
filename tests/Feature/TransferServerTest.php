<?php

use App\Contracts\ProviderContract;
use App\Data\ProviderServerStatus;
use App\Enums\ActivityEvent;
use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRoleKey;
use App\Events\ServerDeleted;
use App\Jobs\RevokeSshKeyJob;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\ServerConnection;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\Providers\ProviderManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake();
    Event::fake([ServerDeleted::class]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create(['name' => 'Origin']);
    $this->destination = Team::factory()->forUser($this->user)->create(['name' => 'Destination']);
    $this->server = Server::factory()->forTeam($this->team)->custom()->active()->create(['name' => 'box-1']);
});

function transferServer(Server $server, Team $from, ?string $toSlug): TestResponse
{
    return test()->post("/{$from->slug}/servers/{$server->id}/transfer", ['team' => $toSlug]);
}

it('moves the server and its sites to the chosen team', function () {
    $site = Site::factory()->forServer($this->server)->create();
    $originMember = User::factory()->adminOf($this->team)->create();

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug)
        ->assertRedirect("/{$this->destination->slug}/servers/{$this->server->id}");

    expect($this->server->fresh()->team_id)->toBe($this->destination->id)
        ->and($site->fresh()->server->team_id)->toBe($this->destination->id);

    $this->get("/{$this->destination->slug}/servers/{$this->server->id}")->assertOk();
    $this->actingAs($originMember)->get("/{$this->team->slug}/servers/{$this->server->id}")->assertForbidden();

    Event::assertDispatched(ServerDeleted::class, fn (ServerDeleted $event) => $event->serverId === $this->server->id
        && $event->teamId === $this->team->id);
});

it('records the transfer in both teams activity logs', function () {
    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug);

    $logs = ActivityLog::query()->where('event', ActivityEvent::ServerTransferred)->get();

    expect($logs->pluck('team_id')->sort()->values()->all())
        ->toBe(collect([$this->team->id, $this->destination->id])->sort()->values()->all())
        ->and($logs->first()->user_id)->toBe($this->user->id)
        ->and($logs->first()->properties)->toBe(['server' => 'box-1', 'from' => 'Origin', 'to' => 'Destination']);
});

it('removes the backup records and schedules of the server but leaves other servers alone', function () {
    $database = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
    $site = Site::factory()->forServer($this->server)->create();
    Backup::factory()->create(['server_database_id' => $database->id]);
    Backup::factory()->forSite($site)->create();
    BackupSchedule::factory()->create(['server_database_id' => $database->id]);
    BackupSchedule::factory()->forSite($site)->create();

    $otherBackup = Backup::factory()->create();
    $otherSchedule = BackupSchedule::factory()->create();

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug)->assertRedirect();

    expect(Backup::query()->pluck('id')->all())->toBe([$otherBackup->id])
        ->and(BackupSchedule::query()->pluck('id')->all())->toBe([$otherSchedule->id]);
});

it('takes ssh keys off for people without server access in the new team and keeps the rest', function () {
    $leftBehind = User::factory()->developerOf($this->team)->create();
    $leftBehindKey = SshKey::factory()->create(['user_id' => $leftBehind->id]);
    $ownerKey = SshKey::factory()->create(['user_id' => $this->user->id]);
    $otherServer = Server::factory()->forTeam($this->team)->create();

    $leftBehindKey->servers()->attach([
        $this->server->id => ['status' => 'synced', 'system_user' => config('server.user')],
        $otherServer->id => ['status' => 'synced', 'system_user' => config('server.user')],
    ]);
    $ownerKey->servers()->attach($this->server->id, ['status' => 'synced', 'system_user' => config('server.user')]);

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug)->assertRedirect();

    Queue::assertPushed(RevokeSshKeyJob::class, 1);
    Queue::assertPushed(RevokeSshKeyJob::class, fn (RevokeSshKeyJob $job) => $job->server->is($this->server)
        && $job->sshKeyId === $leftBehindKey->id);
});

it('only takes keys off the server user for people without admin server access in the new team', function () {
    $developer = User::factory()->developerOf($this->destination)->create();
    $key = SshKey::factory()->create(['user_id' => $developer->id]);
    $site = Site::factory()->forServer($this->server)->isolated('site-user')->create();

    $key->servers()->attach($this->server->id, ['status' => 'synced', 'system_user' => config('server.user')]);
    $key->servers()->attach($this->server->id, ['status' => 'synced', 'system_user' => 'site-user', 'site_id' => $site->id]);

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug)->assertRedirect();

    Queue::assertPushed(RevokeSshKeyJob::class, 1);
    Queue::assertPushed(RevokeSshKeyJob::class, fn (RevokeSshKeyJob $job) => $job->systemUser === config('server.user'));
});

it('expires terminal tokens that were issued but not yet used', function () {
    $this->travelTo(now()->startOfSecond());
    $session = TerminalSession::factory()->create([
        'server_id' => $this->server->id,
        'started_at' => null,
        'expires_at' => now()->addMinute(),
    ]);

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug)->assertRedirect();

    expect($session->fresh()->expires_at->isFuture())->toBeFalse();
});

it('refuses a team the user cannot manage servers in', function (Closure $destination) {
    $destination = $destination($this);

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $destination)
        ->assertSessionHasErrors(['team' => 'Choose another team you can manage servers in.']);

    expect($this->server->fresh()->team_id)->toBe($this->team->id);
})->with([
    'a team they are not in' => [fn () => Team::factory()->create()->slug],
    'a team where they are only a developer' => [function ($test) {
        $team = Team::factory()->create();
        $team->users()->attach($test->user, ['team_role_id' => TeamRole::builtIn(TeamRoleKey::Developer)->id]);

        return $team->slug;
    }],
    'the team it is already in' => [fn ($test) => $test->team->slug],
    'a team that does not exist' => [fn () => 'no-such-team'],
]);

it('refuses a team whose owner\'s Hobby plan has no room for another server', function () {
    $hobbyOwner = User::factory()->onPlan(Plan::Hobby)->create();
    $hobbyTeam = Team::factory()->forUser($hobbyOwner)->create(['name' => 'Acme']);
    Server::factory()->forTeam($hobbyTeam)->create();
    $hobbyTeam->users()->attach($this->user, ['team_role_id' => TeamRole::builtIn(TeamRoleKey::Admin)->id]);

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $hobbyTeam->slug)
        ->assertSessionHasErrors(['team' => "Acme can't take another server. The Hobby plan includes 1 server. Upgrade to Pro to add more."]);

    expect($this->server->fresh()->team_id)->toBe($this->team->id);
});

it('moves a server between two teams of the same Hobby owner', function () {
    $this->user->forceFill(['plan' => Plan::Hobby])->save();

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug)->assertRedirect();

    expect($this->server->fresh()->team_id)->toBe($this->destination->id);
});

it('requires a team', function () {
    $this->actingAs($this->user);

    transferServer($this->server, $this->team, null)
        ->assertSessionHasErrors(['team' => 'Choose a team to transfer this server to.']);
});

it('forbids members who cannot manage servers in the current team', function () {
    $developer = User::factory()->developerOf($this->team)->create();
    $theirTeam = Team::factory()->forUser($developer)->create();

    $this->actingAs($developer);

    transferServer($this->server, $this->team, $theirTeam->slug)->assertForbidden();

    expect($this->server->fresh()->team_id)->toBe($this->team->id);
});

it('refuses a server that is not active', function () {
    $this->server->update(['status' => ServerStatus::Deleting]);

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug)
        ->assertSessionHasErrors(['team' => 'Only an active server can be transferred.']);

    expect($this->server->fresh()->team_id)->toBe($this->team->id);
});

it('refuses a server that is connected to another server', function (string $side) {
    $other = Server::factory()->forTeam($this->team)->create();
    ServerConnection::factory()->create($side === 'host'
        ? ['server_id' => $this->server->id, 'client_server_id' => $other->id]
        : ['server_id' => $other->id, 'client_server_id' => $this->server->id]);

    $this->actingAs($this->user);

    transferServer($this->server, $this->team, $this->destination->slug)
        ->assertSessionHasErrors(['team' => 'Remove this server\'s connections to other servers first.']);

    expect($this->server->fresh()->team_id)->toBe($this->team->id);
})->with(['host', 'client']);

it('offers only the other teams the user can manage servers in on the settings page', function () {
    $developerTeam = Team::factory()->create();
    $developerTeam->users()->attach($this->user, ['team_role_id' => TeamRole::builtIn(TeamRoleKey::Developer)->id]);
    Team::factory()->create();

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/settings")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('transfer_teams', [['slug' => $this->destination->slug, 'name' => 'Destination', 'provider_accounts' => [], 'server_limit' => null]]));
});

it('marks a team whose owner\'s plan is full on the settings page, before any transfer is tried', function () {
    $hobbyOwner = User::factory()->onPlan(Plan::Hobby)->create();
    $hobbyTeam = Team::factory()->forUser($hobbyOwner)->create(['name' => 'Acme']);
    Server::factory()->forTeam($hobbyTeam)->create();
    $hobbyTeam->users()->attach($this->user, ['team_role_id' => TeamRole::builtIn(TeamRoleKey::Admin)->id]);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/settings")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('transfer_teams.0.name', 'Acme')
            ->where('transfer_teams.0.server_limit', [
                'message' => 'The Hobby plan includes 1 server. Upgrade to Pro to add more.',
                'plan' => 'Pro',
            ])
            ->where('transfer_teams.1.name', 'Destination')
            ->where('transfer_teams.1.server_limit', null));
});

describe('cloud servers', function () {
    beforeEach(function () {
        $this->originAccount = ProviderAccount::factory()->forTeam($this->team)->hetzner()->create();
        $this->destinationAccount = ProviderAccount::factory()->forTeam($this->destination)->hetzner()->create(['name' => 'Hetzner main']);
        $this->cloudServer = Server::factory()
            ->forTeam($this->team)
            ->forProviderAccount($this->originAccount)
            ->active()
            ->create(['provider_server_id' => '4711']);

        $this->provider = Mockery::mock(ProviderContract::class);
        $providerManager = Mockery::mock(ProviderManager::class);
        $providerManager->shouldReceive('forAccount')->andReturn($this->provider);
        $this->app->instance(ProviderManager::class, $providerManager);
    });

    it('links the server to the chosen account of the new team', function () {
        $this->provider->shouldReceive('getServerStatus')->once()->with('4711')
            ->andReturn(new ProviderServerStatus(id: '4711', status: 'running', isActive: true));

        $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->cloudServer->id}/transfer", [
                'team' => $this->destination->slug,
                'provider_account' => $this->destinationAccount->ulid,
            ])
            ->assertRedirect("/{$this->destination->slug}/servers/{$this->cloudServer->id}");

        expect($this->cloudServer->fresh())
            ->team_id->toBe($this->destination->id)
            ->provider_account_id->toBe($this->destinationAccount->id);
    });

    it('refuses an account that cannot find the server at the provider', function () {
        $this->provider->shouldReceive('getServerStatus')->andThrow(new RuntimeException('not found'));

        $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->cloudServer->id}/transfer", [
                'team' => $this->destination->slug,
                'provider_account' => $this->destinationAccount->ulid,
            ])
            ->assertSessionHasErrors('provider_account');

        expect($this->cloudServer->fresh())
            ->team_id->toBe($this->team->id)
            ->provider_account_id->toBe($this->originAccount->id);
    });

    it('refuses an account that is not a valid account of the new team for the same provider', function (Closure $account) {
        $this->provider->shouldNotReceive('getServerStatus');

        $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->cloudServer->id}/transfer", [
                'team' => $this->destination->slug,
                'provider_account' => $account($this),
            ])
            ->assertSessionHasErrors('provider_account');

        expect($this->cloudServer->fresh()->team_id)->toBe($this->team->id);
    })->with([
        'none chosen' => [fn () => null],
        'the old team\'s account' => [fn ($test) => $test->originAccount->ulid],
        'another provider' => [fn ($test) => ProviderAccount::factory()->forTeam($test->destination)->digitalocean()->create()->ulid],
        'an invalid account' => [fn ($test) => ProviderAccount::factory()->forTeam($test->destination)->hetzner()->invalid()->create()->ulid],
    ]);

    it('does not ask the provider when the team itself is refused', function () {
        $this->provider->shouldNotReceive('getServerStatus');

        $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->cloudServer->id}/transfer", [
                'team' => Team::factory()->create()->slug,
                'provider_account' => $this->destinationAccount->ulid,
            ])
            ->assertSessionHasErrors('team');
    });

    it('offers each team with its valid accounts for the same provider on the settings page', function () {
        ProviderAccount::factory()->forTeam($this->destination)->digitalocean()->create();
        ProviderAccount::factory()->forTeam($this->destination)->hetzner()->invalid()->create();

        $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->cloudServer->id}/settings")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('transfer_teams', [[
                'slug' => $this->destination->slug,
                'name' => 'Destination',
                'provider_accounts' => [['ulid' => $this->destinationAccount->ulid, 'name' => 'Hetzner main']],
                'server_limit' => null,
            ]]));
    });
});
