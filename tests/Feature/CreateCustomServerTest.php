<?php

use App\Actions\Servers\CreateCustomServerAction;
use App\Data\CustomServerData;
use App\Enums\Provider;
use App\Enums\ServerStatus;
use App\Jobs\InstallStackJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

// --- CreateCustomServerAction ---

it('creates a custom server in pending status with the correct attributes', function () {
    $data = new CustomServerData(
        name: 'my-server',
        ipAddress: '203.0.113.10',
        sshPort: 2222,
        phpVersion: '8.4',
        databaseType: 'postgresql',
    );

    $server = app(CreateCustomServerAction::class)->execute($this->user, $this->team, $data);

    expect($server->provider)->toBe(Provider::Custom)
        ->and($server->provider_account_id)->toBeNull()
        ->and($server->status)->toBe(ServerStatus::Pending)
        ->and($server->name)->toBe('my-server')
        ->and($server->ip_address)->toBe('203.0.113.10')
        ->and($server->ssh_port)->toBe(2222)
        ->and($server->php_version)->toBe('8.4')
        ->and($server->database_type)->toBe('postgresql')
        ->and($server->size)->toBeNull()
        ->and($server->region)->toBeNull();
});

it('generates and stores a fresh keypair in server credentials', function () {
    $data = new CustomServerData(name: 'srv', ipAddress: '1.2.3.4');
    $server = app(CreateCustomServerAction::class)->execute($this->user, $this->team, $data);

    $privateKey = $server->credential('private_key')?->value;
    $publicKey = $server->credential('public_key')?->value;

    expect($privateKey)->not->toBeNull()->toContain('OPENSSH PRIVATE KEY')
        ->and($publicKey)->not->toBeNull()->toStartWith('ssh-ed25519')
        ->and($server->credential('sudo_password'))->not->toBeNull()
        ->and($server->credential('database_password'))->not->toBeNull();
});

it('does not dispatch InstallStackJob on creation', function () {
    Queue::fake();

    $data = new CustomServerData(name: 'srv', ipAddress: '1.2.3.4');
    app(CreateCustomServerAction::class)->execute($this->user, $this->team, $data);

    Queue::assertNothingPushed();
});

it('stores a different keypair for each server', function () {
    $data = new CustomServerData(name: 'srv', ipAddress: '1.2.3.4');

    $serverA = app(CreateCustomServerAction::class)->execute($this->user, $this->team, $data);
    $serverB = app(CreateCustomServerAction::class)->execute($this->user, $this->team, $data);

    expect($serverA->credential('public_key')?->value)
        ->not->toBe($serverB->credential('public_key')?->value);
});

// --- POST /servers/custom (storeCustom) ---

it('creates a custom server and redirects to the setup page', function () {
    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'test-server',
            'ip_address' => '8.8.8.8',
            'ssh_port' => 22,
            'php_version' => '8.3',
            'database_type' => 'mysql',
        ]);

    $server = Server::where('name', 'test-server')->firstOrFail();

    $response->assertRedirect("/{$this->team->slug}/servers/{$server->id}/setup");
    expect($server->status)->toBe(ServerStatus::Pending);
});

it('requires authentication to create a custom server', function () {
    $this->post("/{$this->team->slug}/servers/custom", [
        'name' => 'test-server',
        'ip_address' => '1.2.3.4',
        'ssh_port' => 22,
    ])->assertRedirect('/login');
});

it('validates that name is required', function () {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'ip_address' => '1.2.3.4',
            'ssh_port' => 22,
        ])->assertInvalid(['name']);
});

it('validates that ip_address is required', function () {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'test-server',
            'ssh_port' => 22,
        ])->assertInvalid(['ip_address']);
});

it('validates that name only contains letters numbers and hyphens', function () {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'invalid name!',
            'ip_address' => '1.2.3.4',
            'ssh_port' => 22,
        ])->assertInvalid(['name']);
});

it('validates ssh_port is within valid range', function (int $port) {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'test-server',
            'ip_address' => '1.2.3.4',
            'ssh_port' => $port,
        ])->assertInvalid(['ssh_port']);
})->with([0, 65536, -1]);

// --- GET /servers/{server}/setup ---

it('shows the setup page with the public key command for a pending custom server', function () {
    $server = Server::factory()->custom()->pending()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'ip_address' => '1.2.3.4',
    ]);

    $server->credentials()->createMany([
        ['type' => 'private_key', 'value' => 'fake-private'],
        ['type' => 'public_key', 'value' => 'ssh-ed25519 AAAA fake-key flitops-worker'],
        ['type' => 'sudo_password', 'value' => 'secret'],
        ['type' => 'database_password', 'value' => 'secret'],
    ]);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$server->id}/setup")
        ->assertInertia(fn ($page) => $page
            ->component('servers/setup')
            ->has('authorized_keys_command')
            ->where('authorized_keys_command', fn ($cmd) => str_contains($cmd, 'ssh-ed25519 AAAA fake-key flitops-worker'))
        );
});

it('redirects to show page if the server is no longer pending', function () {
    $server = Server::factory()->custom()->active()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$server->id}/setup")
        ->assertRedirect("/{$this->team->slug}/servers/{$server->id}");
});

// --- POST /servers/{server}/provision ---

it('dispatches InstallStackJob and redirects to show when provisioning starts', function () {
    Queue::fake();

    $server = Server::factory()->custom()->pending()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$server->id}/provision")
        ->assertRedirect("/{$this->team->slug}/servers/{$server->id}");

    expect($server->fresh()->status)->toBe(ServerStatus::Provisioning);
    Queue::assertPushed(InstallStackJob::class, fn ($job) => $job->server->id === $server->id);
});

it('does not re-provision a server that is already provisioning', function () {
    Queue::fake();

    $server = Server::factory()->custom()->provisioning()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$server->id}/provision")
        ->assertRedirect("/{$this->team->slug}/servers/{$server->id}");

    Queue::assertNothingPushed();
});

// --- ip_address must be a public address ---

it('accepts public IPv4 and IPv6 addresses', function (string $ip) {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'test-server',
            'ip_address' => $ip,
            'ssh_port' => 22,
        ])->assertValid(['ip_address']);

    expect(Server::where('ip_address', $ip)->exists())->toBeTrue();
})->with([
    'IPv4' => '8.8.8.8',
    'another IPv4' => '146.190.253.93',
    'IPv6' => '2606:4700:4700::1111',
]);

it('rejects addresses that are not public', function (string $ip) {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'test-server',
            'ip_address' => $ip,
            'ssh_port' => 22,
        ])->assertInvalid(['ip_address']);

    expect(Server::count())->toBe(0);
})->with([
    'IPv4 loopback' => '127.0.0.1',
    'IPv4 loopback range' => '127.1.2.3',
    'private 10/8' => '10.0.0.5',
    'private 172.16/12' => '172.16.0.1',
    'private 192.168/16' => '192.168.1.100',
    'cloud metadata' => '169.254.169.254',
    'IPv4 unspecified' => '0.0.0.0',
    'carrier-grade NAT' => '100.64.0.1',
    'IPv4 broadcast' => '255.255.255.255',
    'IPv4 reserved' => '240.0.0.1',
    'IPv4 multicast' => '224.0.0.1',
    'IPv4 documentation' => '203.0.113.10',
    'IPv6 loopback' => '::1',
    'IPv6 unspecified' => '::',
    'IPv6 unique local' => 'fd00::1',
    'IPv6 unique local fc00' => 'fc00::1',
    'IPv6 link-local' => 'fe80::1',
    'IPv6 multicast' => 'ff02::1',
    'IPv4-mapped loopback' => '::ffff:127.0.0.1',
    'IPv4-mapped metadata' => '::ffff:169.254.169.254',
    'NAT64 to private' => '64:ff9b::a00:1',
    '6to4 to loopback' => '2002:7f00:1::',
]);

it('rejects hostnames and malformed addresses', function (string $value) {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'test-server',
            'ip_address' => $value,
            'ssh_port' => 22,
        ])->assertInvalid(['ip_address']);
})->with([
    'hostname' => 'example.com',
    'localhost' => 'localhost',
    'incomplete IPv4' => '1.2.3',
    'IPv4 with port' => '8.8.8.8:22',
]);

it('accepts private addresses when the self-hosting switch is on', function (string $ip) {
    config(['server.allow_private_addresses' => true]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'test-server',
            'ip_address' => $ip,
            'ssh_port' => 22,
        ])->assertValid(['ip_address']);
})->with(['192.168.1.100', '10.0.0.5', 'fd00::1']);

it('still rejects hostnames when the self-hosting switch is on', function () {
    config(['server.allow_private_addresses' => true]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/custom", [
            'name' => 'test-server',
            'ip_address' => 'example.com',
            'ssh_port' => 22,
        ])->assertInvalid(['ip_address']);
});
