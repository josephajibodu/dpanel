<?php

use App\Events\ServerConnectionTested;
use App\Jobs\TestServerConnectionJob;
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
    $this->server = Server::factory()->custom()->pending()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);
});

// --- TestServerConnectionJob ---

it('broadcasts a successful ServerConnectionTested event when SSH connects', function () {
    Event::fake();

    $connection = $this->createMock(SshConnection::class);
    $connection->expects($this->once())->method('disconnect');

    $sshService = mock(SshService::class)
        ->shouldReceive('connectAsRoot')
        ->with($this->server)
        ->once()
        ->andReturn($connection)
        ->getMock();

    (new TestServerConnectionJob($this->server))->handle($sshService);

    Event::assertDispatched(ServerConnectionTested::class, function ($event) {
        return $event->server->id === $this->server->id
            && $event->successful === true
            && $event->message === 'Connected successfully.';
    });
});

it('broadcasts a failed ServerConnectionTested event when SSH throws', function () {
    Event::fake();

    $sshService = mock(SshService::class)
        ->shouldReceive('connectAsRoot')
        ->with($this->server)
        ->once()
        ->andThrow(new \RuntimeException('Connection refused'))
        ->getMock();

    (new TestServerConnectionJob($this->server))->handle($sshService);

    Event::assertDispatched(ServerConnectionTested::class, function ($event) {
        return $event->server->id === $this->server->id
            && $event->successful === false
            && $event->message === 'Connection refused';
    });
});

it('has a single try and a 30-second timeout', function () {
    $job = new TestServerConnectionJob($this->server);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(30);
});

// --- POST /servers/{server}/test-connection ---

it('dispatches TestServerConnectionJob when test-connection is posted', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/test-connection")
        ->assertRedirect();

    Queue::assertPushed(TestServerConnectionJob::class, fn ($job) => $job->server->id === $this->server->id);
});

it('requires authentication to test a connection', function () {
    Queue::fake();

    $this->post("/{$this->team->slug}/servers/{$this->server->id}/test-connection")
        ->assertRedirect('/login');

    Queue::assertNothingPushed();
});

it('forbids testing connection on a server belonging to another user', function () {
    Queue::fake();

    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->forUser($otherUser)->create();
    $otherServer = Server::factory()->custom()->pending()->create([
        'team_id' => $otherTeam->id,
        'user_id' => $otherUser->id,
    ]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$otherServer->id}/test-connection")
        ->assertForbidden();

    Queue::assertNothingPushed();
});
