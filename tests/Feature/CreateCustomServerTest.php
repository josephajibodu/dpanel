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
            'ip_address' => '192.168.1.100',
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
