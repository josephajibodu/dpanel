<?php

use App\Enums\ServerConnectionStatus;
use App\Jobs\AllowServerConnectionJob;
use App\Jobs\RevokeServerConnectionJob;
use App\Models\Server;
use App\Models\ServerConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->host = Server::factory()->forTeam($this->team)->database()->create([
        'provider' => 'digitalocean',
        'region' => 'nyc1',
        'ip_address' => '203.0.113.10',
        'private_ip_address' => '10.10.0.2',
    ]);
    $this->client = Server::factory()->forTeam($this->team)->web()->create([
        'name' => 'web-1',
        'provider' => 'digitalocean',
        'region' => 'nyc1',
        'ip_address' => '203.0.113.20',
        'private_ip_address' => '10.10.0.3',
    ]);
});

it('returns not found on a server with nothing to share', function () {
    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->client->id}/connections")
        ->assertNotFound();
});

it('lists connections and the team servers that can still be connected', function () {
    ServerConnection::factory()->create(['server_id' => $this->host->id, 'client_server_id' => $this->client->id]);
    $unconnected = Server::factory()->forTeam($this->team)->web()->create(['name' => 'web-2', 'region' => 'lon1']);
    Server::factory()->web()->create(['name' => 'other-team']);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->host->id}/connections")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('servers/connections/index')
            ->where('services', [['label' => 'MySQL', 'port' => 3306]])
            ->where('connections.0.client_name', 'web-1')
            ->where('availableServers', [['id' => $unconnected->id, 'name' => 'web-2', 'private_network' => false]])
        );
});

it('refreshes the connections, connectable servers and Redis password when this server broadcasts a connection change', function () {
    $listener = "private:server.{$this->host->id} server.connections.updated";

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->host->id}/connections");

    expect(liveProps($response))->toBe([
        'connections' => [$listener],
        'availableServers' => [$listener],
        'redisPassword' => [$listener],
    ]);
});

it('connects a server over the private network when both share one', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->host->id}/connections", ['client_server_id' => $this->client->id])
        ->assertRedirect();

    $connection = $this->host->hostedConnections()->sole();
    expect($connection)
        ->client_server_id->toBe($this->client->id)
        ->host_address->toBe('10.10.0.2')
        ->client_address->toBe('10.10.0.3')
        ->status->toBe(ServerConnectionStatus::Pending);
    Queue::assertPushed(AllowServerConnectionJob::class, fn (AllowServerConnectionJob $job) => $job->serverConnection->is($connection));
});

it('connects over public addresses when the servers are in different regions', function () {
    Queue::fake();
    $this->client->update(['region' => 'ams3']);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->host->id}/connections", ['client_server_id' => $this->client->id])
        ->assertRedirect();

    expect($this->host->hostedConnections()->sole())
        ->host_address->toBe('203.0.113.10')
        ->client_address->toBe('203.0.113.20');
});

it('refuses a server from another team', function () {
    Queue::fake();
    $stranger = Server::factory()->web()->create();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->host->id}/connections", ['client_server_id' => $stranger->id])
        ->assertInvalid(['client_server_id' => "Choose one of your team's servers."]);

    expect($this->host->hostedConnections()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('refuses a server that is already connected', function () {
    Queue::fake();
    ServerConnection::factory()->create(['server_id' => $this->host->id, 'client_server_id' => $this->client->id]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->host->id}/connections", ['client_server_id' => $this->client->id])
        ->assertInvalid(['client_server_id' => 'That server is already connected.']);

    Queue::assertNothingPushed();
});

it('starts removing a connection', function () {
    Queue::fake();
    $connection = ServerConnection::factory()->create(['server_id' => $this->host->id, 'client_server_id' => $this->client->id]);

    $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->host->id}/connections/{$connection->id}")
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(ServerConnectionStatus::Removing);
    Queue::assertPushed(RevokeServerConnectionJob::class, fn (RevokeServerConnectionJob $job) => $job->serverConnection->is($connection));
});

it('does not remove another server\'s connection through this server', function () {
    Queue::fake();
    $otherHost = Server::factory()->forTeam($this->team)->database()->create();
    $connection = ServerConnection::factory()->create(['server_id' => $otherHost->id, 'client_server_id' => $this->client->id]);

    $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->host->id}/connections/{$connection->id}")
        ->assertNotFound();

    expect($connection->fresh()->status)->toBe(ServerConnectionStatus::Active);
    Queue::assertNothingPushed();
});
