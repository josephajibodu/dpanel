<?php

use App\Enums\ServerStatus;
use App\Enums\TeamRoleKey;
use App\Jobs\RevokeSshKeyJob;
use App\Jobs\SyncSshKeyJob;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Models\Site;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

const SITE_ACCESS_PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC6nEXz5T1X6J8sB1oT4yQwertyuiopasdfghjklzxcv dev@example.com';

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->forTeam($this->team)->create(['status' => ServerStatus::Active]);
    $this->site = Site::factory()->forServer($this->server)->isolated('example_com')->create(['domain' => 'example.com']);
});

function memberWithKey(Team $team, TeamRoleKey $role): array
{
    $member = User::factory()->memberOf($team, $role)->create();
    $member->switchTeam($team);
    $sshKey = SshKey::factory()->create(['user_id' => $member->id, 'public_key' => SITE_ACCESS_PUBLIC_KEY]);

    return [$member, $sshKey];
}

// --------------------------------------------------------------------------
// Who may put a key where
// --------------------------------------------------------------------------

it('lets only Owners and Admins put a key on the server user', function (TeamRoleKey $role, bool $allowed) {
    Queue::fake();
    [$member, $sshKey] = memberWithKey($this->team, $role);

    $response = $this->actingAs($member)
        ->post(route('ssh-keys.sync', [$this->team, $sshKey]), ['server_ids' => [$this->server->id]]);

    if ($allowed) {
        $response->assertRedirect();
        Queue::assertPushed(SyncSshKeyJob::class, fn ($job) => $job->server->is($this->server) && $job->site === null);
        expect(ServerSshKey::query()->sole()->system_user)->toBe('artisan');
    } else {
        $response->assertForbidden();
        Queue::assertNotPushed(SyncSshKeyJob::class);
        expect(ServerSshKey::query()->count())->toBe(0);
    }
})->with([
    'owner' => [TeamRoleKey::Owner, true],
    'admin' => [TeamRoleKey::Admin, true],
    'developer' => [TeamRoleKey::Developer, false],
    'viewer' => [TeamRoleKey::Viewer, false],
]);

it('lets a Developer put a key on an isolated site user', function () {
    Queue::fake();
    [$member, $sshKey] = memberWithKey($this->team, TeamRoleKey::Developer);

    $this->actingAs($member)
        ->post(route('ssh-keys.sync', [$this->team, $sshKey]), ['site_ids' => [$this->site->id]])
        ->assertRedirect();

    Queue::assertPushed(SyncSshKeyJob::class, fn ($job) => $job->site?->is($this->site) && $job->server->is($this->server));

    $placement = ServerSshKey::query()->sole();
    expect($placement->system_user)->toBe('example_com')
        ->and($placement->site_id)->toBe($this->site->id)
        ->and($placement->status)->toBe('pending');
});

it('stops a Viewer putting a key anywhere', function () {
    Queue::fake();
    [$member, $sshKey] = memberWithKey($this->team, TeamRoleKey::Viewer);

    $this->actingAs($member)
        ->post(route('ssh-keys.sync', [$this->team, $sshKey]), ['site_ids' => [$this->site->id]])
        ->assertForbidden();
});

it('ignores sites that are not isolated or belong to another team', function () {
    Queue::fake();
    [$member, $sshKey] = memberWithKey($this->team, TeamRoleKey::Developer);

    $plainSite = Site::factory()->forServer($this->server)->create();
    $otherTeamSite = Site::factory()->forServer(Server::factory()->create(['status' => ServerStatus::Active]))->isolated('other_com')->create();

    $this->actingAs($member)
        ->post(route('ssh-keys.sync', [$this->team, $sshKey]), ['site_ids' => [$plainSite->id, $otherTeamSite->id]])
        ->assertRedirect();

    Queue::assertNotPushed(SyncSshKeyJob::class);
    expect(ServerSshKey::query()->count())->toBe(0);
});

it('keeps a key on the server user and a site user of the same server side by side', function () {
    Queue::fake();
    [$member, $sshKey] = memberWithKey($this->team, TeamRoleKey::Admin);

    $this->actingAs($member)
        ->post(route('ssh-keys.sync', [$this->team, $sshKey]), ['server_ids' => [$this->server->id], 'site_ids' => [$this->site->id]])
        ->assertRedirect();

    expect(ServerSshKey::query()->where('server_id', $this->server->id)->pluck('system_user')->sort()->values()->all())
        ->toBe(['artisan', 'example_com']);
});

it('tells the keys page whether full server access is allowed and lists isolated sites', function () {
    [$member] = memberWithKey($this->team, TeamRoleKey::Developer);
    Site::factory()->forServer($this->server)->create();

    $this->actingAs($member)
        ->get(route('ssh-keys.index', $this->team))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSyncToServerUser', false)
            ->has('isolatedSites', 1)
            ->where('isolatedSites.0.system_user', 'example_com')
            ->where('isolatedSites.0.domain', 'example.com'));
});

// --------------------------------------------------------------------------
// On the server
// --------------------------------------------------------------------------

function sshKeyConnection(array &$commands): SshService
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return '';
    });
    $connection->shouldReceive('disconnect');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);

    return $sshService;
}

it('adds the key to the site user own authorized_keys, as that user', function () {
    [, $sshKey] = memberWithKey($this->team, TeamRoleKey::Developer);
    ServerSshKey::query()->create(['ssh_key_id' => $sshKey->id, 'server_id' => $this->server->id, 'site_id' => $this->site->id, 'system_user' => 'example_com', 'status' => 'pending']);

    $commands = [];
    (new SyncSshKeyJob($sshKey, $this->server, $this->site))->handle(sshKeyConnection($commands));

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toStartWith("sudo -u 'example_com' -H bash -c 'mkdir -p ~/.ssh && chmod 700 ~/.ssh")
        ->and($commands[0])->toContain('~/.ssh/authorized_keys')
        ->and($commands[0])->not->toContain('/home/artisan')
        ->and(ServerSshKey::query()->sole()->status)->toBe('synced');
});

it('refuses to put a key on a site that is not isolated', function () {
    [, $sshKey] = memberWithKey($this->team, TeamRoleKey::Developer);
    $plainSite = Site::factory()->forServer($this->server)->create();

    $commands = [];

    expect(fn () => (new SyncSshKeyJob($sshKey, $this->server, $plainSite))->handle(sshKeyConnection($commands)))
        ->toThrow(RuntimeException::class);

    expect($commands)->toBe([]);
});

it('takes the key off only the site user it was placed on', function () {
    [, $sshKey] = memberWithKey($this->team, TeamRoleKey::Admin);
    ServerSshKey::query()->create(['ssh_key_id' => $sshKey->id, 'server_id' => $this->server->id, 'system_user' => 'artisan', 'status' => 'synced']);
    ServerSshKey::query()->create(['ssh_key_id' => $sshKey->id, 'server_id' => $this->server->id, 'site_id' => $this->site->id, 'system_user' => 'example_com', 'status' => 'synced']);

    $commands = [];
    (new RevokeSshKeyJob($sshKey, $this->server, 'example_com'))->handle(sshKeyConnection($commands));

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toStartWith("if id -u 'example_com' >/dev/null 2>&1; then sudo -u 'example_com' -H bash -c")
        ->and($commands[0])->toContain('/home/example_com/.ssh/authorized_keys')
        ->and(ServerSshKey::query()->pluck('system_user')->all())->toBe(['artisan']);
});

it('revokes a key from a chosen site through the keys page', function () {
    Queue::fake();
    [$member, $sshKey] = memberWithKey($this->team, TeamRoleKey::Developer);
    ServerSshKey::query()->create(['ssh_key_id' => $sshKey->id, 'server_id' => $this->server->id, 'site_id' => $this->site->id, 'system_user' => 'example_com', 'status' => 'synced']);

    $this->actingAs($member)
        ->post(route('ssh-keys.revoke', [$this->team, $sshKey]), ['site_ids' => [$this->site->id]])
        ->assertRedirect();

    Queue::assertPushed(RevokeSshKeyJob::class, fn ($job) => $job->systemUser === 'example_com' && $job->server->is($this->server));
    expect(ServerSshKey::query()->sole()->status)->toBe('revoking');
});

it('revokes every placement with its own user when the key is deleted', function () {
    Queue::fake();
    [$member, $sshKey] = memberWithKey($this->team, TeamRoleKey::Admin);
    ServerSshKey::query()->create(['ssh_key_id' => $sshKey->id, 'server_id' => $this->server->id, 'system_user' => 'artisan', 'status' => 'synced']);
    ServerSshKey::query()->create(['ssh_key_id' => $sshKey->id, 'server_id' => $this->server->id, 'site_id' => $this->site->id, 'system_user' => 'example_com', 'status' => 'synced']);

    $this->actingAs($member)
        ->delete(route('ssh-keys.destroy', [$this->team, $sshKey]))
        ->assertRedirect();

    Queue::assertPushed(RevokeSshKeyJob::class, 2);
    Queue::assertPushed(RevokeSshKeyJob::class, fn ($job) => $job->systemUser === 'artisan');
    Queue::assertPushed(RevokeSshKeyJob::class, fn ($job) => $job->systemUser === 'example_com');
});

// --------------------------------------------------------------------------
// Role changes and removal
// --------------------------------------------------------------------------

/**
 * A member with one key on the server user and on an isolated site user.
 */
function memberWithBothPlacements(Team $team, Server $server, Site $site, TeamRoleKey $role): array
{
    [$member, $sshKey] = memberWithKey($team, $role);

    ServerSshKey::query()->create(['ssh_key_id' => $sshKey->id, 'server_id' => $server->id, 'system_user' => 'artisan', 'status' => 'synced']);
    ServerSshKey::query()->create(['ssh_key_id' => $sshKey->id, 'server_id' => $server->id, 'site_id' => $site->id, 'system_user' => 'example_com', 'status' => 'synced']);

    return [$member, $sshKey];
}

it('takes keys off the server user but not isolated sites when a member drops to Developer', function (TeamRoleKey $from) {
    Queue::fake();
    [$member] = memberWithBothPlacements($this->team, $this->server, $this->site, $from);

    $this->actingAs($this->team->owner)
        ->patch(route('team-members.update', [$this->team, $member]), ['role' => 'developer'])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(RevokeSshKeyJob::class, 1);
    Queue::assertPushed(RevokeSshKeyJob::class, fn ($job) => $job->systemUser === 'artisan');

    expect(ServerSshKey::query()->where('system_user', 'artisan')->sole()->status)->toBe('revoking')
        ->and(ServerSshKey::query()->where('system_user', 'example_com')->sole()->status)->toBe('synced');
})->with([
    'admin' => [TeamRoleKey::Admin],
    // A Developer who put a key on the server user before admin access was its own permission.
    'developer' => [TeamRoleKey::Developer],
]);

it('takes keys off isolated sites too when a member loses server access', function () {
    Queue::fake();
    [$member] = memberWithBothPlacements($this->team, $this->server, $this->site, TeamRoleKey::Developer);

    $this->actingAs($this->team->owner)
        ->patch(route('team-members.update', [$this->team, $member]), ['role' => 'viewer'])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(RevokeSshKeyJob::class, 2);
    Queue::assertPushed(RevokeSshKeyJob::class, fn ($job) => $job->systemUser === 'example_com');
});

it('takes keys off isolated sites too when a member is removed', function () {
    Queue::fake();
    [$member] = memberWithBothPlacements($this->team, $this->server, $this->site, TeamRoleKey::Developer);

    $this->actingAs($this->team->owner)
        ->delete(route('team-members.destroy', [$this->team, $member]))
        ->assertSessionHasNoErrors();

    Queue::assertPushed(RevokeSshKeyJob::class, 2);
    Queue::assertPushed(RevokeSshKeyJob::class, fn ($job) => $job->systemUser === 'example_com');
});

it('records existing keys as being on the server user', function () {
    [, $sshKey] = memberWithKey($this->team, TeamRoleKey::Admin);

    $sshKey->servers()->attach($this->server->id, ['status' => 'synced']);

    expect(ServerSshKey::query()->sole()->system_user)->toBe('artisan')
        ->and(ServerSshKey::query()->sole()->site_id)->toBeNull();
});
