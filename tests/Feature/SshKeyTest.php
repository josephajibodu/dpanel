<?php

use App\Http\Resources\SshKeyResource;
use App\Jobs\RevokeSshKeyJob;
use App\Jobs\SyncSshKeyJob;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

const VALID_SSH_PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC6nEXz5T1X6J8sB1oT4yQwertyuiopasdfghjklzxcv test@example.com';

it('stores an ssh key under the team prefix', function () {
    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'My MacBook',
            'public_key' => VALID_SSH_PUBLIC_KEY,
        ])
        ->assertRedirect(route('ssh-keys.index', $this->team))
        ->assertSessionHas('success', 'SSH key added successfully.');

    $this->assertDatabaseHas('ssh_keys', [
        'user_id' => $this->user->id,
        'name' => 'My MacBook',
    ]);
});

it('rejects an invalid ssh public key', function () {
    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'Bad Key',
            'public_key' => 'not-a-valid-key',
        ])
        ->assertSessionHasErrors('public_key');

    $this->assertDatabaseCount('ssh_keys', 0);
});

it('rejects duplicate ssh keys', function () {
    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'First Key',
            'public_key' => VALID_SSH_PUBLIC_KEY,
        ])
        ->assertRedirect();

    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'Duplicate Key',
            'public_key' => VALID_SSH_PUBLIC_KEY,
        ])
        ->assertSessionHasErrors('public_key');

    $this->assertDatabaseCount('ssh_keys', 1);
});

it('serializes synced_at on attached servers as an iso8601 string', function () {
    $sshKey = SshKey::factory()->create(['user_id' => $this->user->id]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);

    $sshKey->servers()->attach($server->id, [
        'status' => 'synced',
        'synced_at' => now(),
    ]);

    $sshKey->load('servers:id,ulid,name');

    $resource = SshKeyResource::make($sshKey)->resolve();

    expect($resource['servers'][0]['synced_at'])->toBeString()
        ->and($resource['servers'][0]['status'])->toBe('synced');
});

it('deletes an ssh key under the team prefix', function () {
    $sshKey = SshKey::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->delete(route('ssh-keys.destroy', [$this->team, $sshKey]))
        ->assertRedirect(route('ssh-keys.index', $this->team))
        ->assertSessionHas('success', 'SSH key deleted successfully.');

    $this->assertDatabaseMissing('ssh_keys', ['id' => $sshKey->id]);
});

it('rejects ssh public keys that could break out of a single authorized_keys line', function (string $publicKey) {
    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'Sneaky Key',
            'public_key' => $publicKey,
        ])
        ->assertSessionHasErrors('public_key');

    $this->assertDatabaseCount('ssh_keys', 0);
})->with([
    'newline in comment' => [VALID_SSH_PUBLIC_KEY."\nssh-ed25519 AAAAC3NzaC1lZDI1NTE5 attacker"],
    'carriage return in comment' => [VALID_SSH_PUBLIC_KEY."\rcommand=\"id\""],
    'control character in comment' => [VALID_SSH_PUBLIC_KEY."\x1b[2J"],
    'shell characters in key material' => ['ssh-ed25519 AAAAC3NzaC1l$(id)ZDI1NTE5 test@example.com'],
]);

it('accepts an ssh public key with a multi-word comment', function () {
    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'Work Laptop',
            'public_key' => VALID_SSH_PUBLIC_KEY.' work laptop',
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('ssh_keys', 1);
});

it('only syncs an ssh key to active servers of the team in the url', function () {
    Queue::fake();

    $teammate = User::factory()->developerOf($this->team)->create();

    $ownTeamServer = Server::factory()->forTeam($this->team)->create();
    $teammateServer = Server::factory()->forTeam($this->team)->forUser($teammate)->create();
    $otherTeamServer = Server::factory()->forUser($this->user)->create();

    $sshKey = SshKey::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->post(route('ssh-keys.sync', [$this->team, $sshKey]), [
            'server_ids' => [$ownTeamServer->id, $teammateServer->id, $otherTeamServer->id],
        ])
        ->assertRedirect(route('ssh-keys.index', $this->team));

    expect($sshKey->servers()->pluck('servers.id')->sort()->values()->all())
        ->toBe(collect([$ownTeamServer->id, $teammateServer->id])->sort()->values()->all());

    Queue::assertPushed(SyncSshKeyJob::class, 2);
    Queue::assertNotPushed(SyncSshKeyJob::class, fn (SyncSshKeyJob $job) => $job->server->is($otherTeamServer));
});

it('does not sync an ssh key to a server the user created in a team they have left', function () {
    Queue::fake();

    $formerTeam = Team::factory()->create();
    $serverInFormerTeam = Server::factory()->forTeam($formerTeam)->forUser($this->user)->create();
    $sshKey = SshKey::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->post(route('ssh-keys.sync', [$this->team, $sshKey]), [
            'server_ids' => [$serverInFormerTeam->id],
        ])
        ->assertRedirect();

    expect($sshKey->servers()->count())->toBe(0);
    Queue::assertNotPushed(SyncSshKeyJob::class);
});

it('quotes the stored key when syncing it, so its text is never run by the shell', function () {
    $server = Server::factory()->forTeam($this->team)->create();
    $sshKey = SshKey::factory()->create([
        'user_id' => $this->user->id,
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC6nEXz5 me$(touch /tmp/pwned)\'"`id`',
    ]);
    $sshKey->servers()->attach($server->id, ['status' => 'pending']);

    $commands = [];
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return 'not_found';
    });
    $connection->shouldReceive('disconnect');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);

    (new SyncSshKeyJob($sshKey, $server))->handle($sshService);

    $quotedKey = escapeshellarg($sshKey->public_key);
    $authorizedKeys = escapeshellarg('/home/'.config('server.user').'/.ssh/authorized_keys');

    expect($commands)->toBe([
        "grep -qxF -- {$quotedKey} {$authorizedKeys} && echo \"exists\" || echo \"not_found\"",
        "printf '%s\\n' {$quotedKey} >> {$authorizedKeys}",
    ]);
    expect($sshKey->servers()->first()->pivot->status)->toBe('synced');
});

it('revokes a key by its quoted key material rather than a regex prefix', function () {
    $server = Server::factory()->forTeam($this->team)->create();
    $sshKey = SshKey::factory()->create([
        'user_id' => $this->user->id,
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC6nEXz5 me$(touch /tmp/pwned)',
    ]);
    $sshKey->servers()->attach($server->id, ['status' => 'synced']);

    $commands = [];
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return '';
    });
    $connection->shouldReceive('disconnect');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);

    (new RevokeSshKeyJob($sshKey, $server))->handle($sshService);

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toStartWith("awk -v key='AAAAC3NzaC1lZDI1NTE5AAAAIC6nEXz5' ")
        ->and($commands[0])->not->toContain('$(touch');
    expect($sshKey->servers()->count())->toBe(0);
});

it('still revokes a deleted ssh key from its servers once the queued job runs', function () {
    Queue::fake();

    $firstServer = Server::factory()->forTeam($this->team)->create();
    $secondServer = Server::factory()->forTeam($this->team)->create();
    $sshKey = SshKey::factory()->create([
        'user_id' => $this->user->id,
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDeletedKeyMaterial me@laptop',
    ]);
    $sshKey->servers()->attach([
        $firstServer->id => ['status' => 'synced'],
        $secondServer->id => ['status' => 'synced'],
    ]);

    $this->actingAs($this->user)
        ->delete(route('ssh-keys.destroy', [$this->team, $sshKey]))
        ->assertRedirect(route('ssh-keys.index', $this->team));

    $this->assertDatabaseMissing('ssh_keys', ['id' => $sshKey->id]);

    $commandsByServer = [];
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturnUsing(function (Server $server) use (&$commandsByServer) {
        $connection = Mockery::mock(SshConnection::class);
        $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commandsByServer, $server) {
            $commandsByServer[$server->id][] = $command;

            return '';
        });
        $connection->shouldReceive('disconnect');

        return $connection;
    });

    // Round-trip each job through serialization, as a real queue worker would,
    // now that the key row is gone.
    Queue::pushed(RevokeSshKeyJob::class)->each(
        fn (RevokeSshKeyJob $job) => unserialize(serialize($job))->handle($sshService)
    );

    expect(array_keys($commandsByServer))->toEqualCanonicalizing([$firstServer->id, $secondServer->id]);

    foreach ($commandsByServer as $commands) {
        expect($commands)->toHaveCount(1)
            ->and($commands[0])->toStartWith("awk -v key='AAAAC3NzaC1lZDI1NTE5AAAAIDeletedKeyMaterial' ");
    }
});
