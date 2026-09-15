<?php

use App\Events\ServerMetricsUpdated;
use App\Jobs\CollectServerMetricsJob;
use App\Models\Metric;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->custom()->active()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);
});

// --- CollectServerMetricsJob ---

it('stores a metric and broadcasts an update when SSH succeeds', function () {
    Event::fake();

    $connection = $this->createMock(SshConnection::class);
    $connection->method('exec')->willReturnOnConsecutiveCalls(
        '0.42',
        '16777216000 8388608000 7516192768',
        '107374182400 32212254720 70464212480',
    );
    $connection->expects($this->once())->method('disconnect');

    $sshService = mock(SshService::class)
        ->shouldReceive('connect')
        ->with($this->server)
        ->once()
        ->andReturn($connection)
        ->getMock();

    (new CollectServerMetricsJob($this->server))->handle($sshService);

    expect(Metric::count())->toBe(1);

    $metric = Metric::first();

    expect($metric->server_id)->toBe($this->server->id)
        ->and($metric->load)->toBe(0.42)
        ->and($metric->memory_total)->toBe(16777216000)
        ->and($metric->memory_used)->toBe(8388608000)
        ->and($metric->memory_free)->toBe(7516192768)
        ->and($metric->disk_total)->toBe(107374182400)
        ->and($metric->disk_used)->toBe(32212254720)
        ->and($metric->disk_free)->toBe(70464212480);

    Event::assertDispatched(ServerMetricsUpdated::class, function ($event) use ($metric) {
        return $event->server->id === $this->server->id
            && $event->metric->id === $metric->id;
    });
});

it('does not store a metric or broadcast when SSH throws', function () {
    Event::fake();

    $sshService = mock(SshService::class)
        ->shouldReceive('connect')
        ->with($this->server)
        ->once()
        ->andThrow(new RuntimeException('Connection refused'))
        ->getMock();

    (new CollectServerMetricsJob($this->server))->handle($sshService);

    expect(Metric::count())->toBe(0);

    Event::assertNotDispatched(ServerMetricsUpdated::class);
});

it('has a single try and a 30-second timeout', function () {
    $job = new CollectServerMetricsJob($this->server);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(30);
});

// --- POST /servers/{server}/metrics/refresh ---

it('dispatches CollectServerMetricsJob when metrics refresh is posted', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/metrics/refresh")
        ->assertRedirect();

    Queue::assertPushed(CollectServerMetricsJob::class, fn ($job) => $job->server->id === $this->server->id);
});

it('requires authentication to refresh metrics', function () {
    Queue::fake();

    $this->post("/{$this->team->slug}/servers/{$this->server->id}/metrics/refresh")
        ->assertRedirect('/login');

    Queue::assertNothingPushed();
});

it('forbids refreshing metrics on a server belonging to another user', function () {
    Queue::fake();

    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->forUser($otherUser)->create();
    $otherServer = Server::factory()->custom()->active()->create([
        'team_id' => $otherTeam->id,
        'user_id' => $otherUser->id,
    ]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$otherServer->id}/metrics/refresh")
        ->assertForbidden();

    Queue::assertNothingPushed();
});
