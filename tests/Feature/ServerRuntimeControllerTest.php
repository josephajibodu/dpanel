<?php

use App\Enums\ConnectionStatus;
use App\Enums\NodeVersion;
use App\Enums\ServiceStatus;
use App\Jobs\InstallNodeVersionJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->active()->create([
        'node_version' => NodeVersion::Node20,
    ]);
    $this->path = "/{$this->team->slug}/servers/{$this->server->id}/runtime";
});

it('shows the installed Node.js version and the versions to switch to', function () {
    $response = $this->actingAs($this->user)->get($this->path);

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('servers/runtime/index')
            ->where('serverIsReady', true)
            ->where('node.version', '20')
            ->where('node.label', 'Node.js 20 (end of life)')
            ->where('node.is_end_of_life', true)
            ->where('node.status', 'active')
            ->where('availableNodeVersions.2', ['value' => '24', 'label' => 'Node.js 24'])
        );
});

it('records new servers as Node.js 24', function () {
    $server = Server::factory()->forTeam($this->team)->create();

    expect($server->fresh()->node_version)->toBe(NodeVersion::Node24);
});

it('denies the runtime page for another team\'s server', function () {
    $response = $this->actingAs(User::factory()->create())->get($this->path);

    $response->assertForbidden();
});

it('queues a switch to the chosen Node.js version', function () {
    $response = $this->actingAs($this->user)->put("{$this->path}/node", ['version' => '24']);

    $response->assertRedirect()->assertSessionHas('success', 'Switching to Node.js 24.');
    expect($this->server->fresh()->node_status)->toBe(ServiceStatus::Pending);
    Queue::assertPushed(InstallNodeVersionJob::class, fn (InstallNodeVersionJob $job) => $job->server->is($this->server)
        && $job->version === NodeVersion::Node24);
});

it('refuses a version switch from a viewer', function () {
    $viewer = User::factory()->viewerOf($this->team)->create();

    $response = $this->actingAs($viewer)->put("{$this->path}/node", ['version' => '24']);

    $response->assertForbidden();
    Queue::assertNothingPushed();
});

it('rejects a Node.js version that is not offered', function () {
    $response = $this->actingAs($this->user)->put("{$this->path}/node", ['version' => '18']);

    $response->assertSessionHasErrors(['version' => 'The selected Node.js version is not available.']);
    Queue::assertNothingPushed();
});

it('does not switch versions in these cases', function (array $serverState, array $payload, string $error) {
    $this->server->update($serverState);

    $response = $this->actingAs($this->user)->put("{$this->path}/node", $payload);

    $response->assertRedirect()->assertSessionHas('error', $error);
    Queue::assertNothingPushed();
})->with([
    'server not connected' => [
        ['connection_status' => ConnectionStatus::Failed],
        ['version' => '24'],
        'Server must be active and connected to change the Node.js version.',
    ],
    'a switch already in progress' => [
        ['node_status' => ServiceStatus::Installing],
        ['version' => '24'],
        'A Node.js version change is already in progress.',
    ],
    'already on that version' => [
        [],
        ['version' => '20'],
        'Node.js 20 is already installed.',
    ],
]);

it('allows retrying the current version after a failed switch', function () {
    $this->server->update(['node_status' => ServiceStatus::Failed]);

    $response = $this->actingAs($this->user)->put("{$this->path}/node", ['version' => '20']);

    $response->assertSessionHas('success');
    Queue::assertPushed(InstallNodeVersionJob::class);
});
