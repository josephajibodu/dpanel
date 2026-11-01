<?php

use App\Enums\ServerStatus;
use App\Events\ServerProcessesUpdated;
use App\Jobs\SyncWorkerStatusesJob;
use App\Models\Server;
use App\Models\Worker;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::Active]);
});

/**
 * An SSH service whose `supervisorctl status` call returns the given output.
 */
function supervisorStatusService(Server $server, string $output): SshService
{
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('sudo')->once()->with('supervisorctl status || true', 20)->andReturn($output);
    $connection->shouldReceive('disconnect')->once();

    return mock(SshService::class)
        ->shouldReceive('connect')
        ->with($server)
        ->once()
        ->andReturn($connection)
        ->getMock();
}

it('updates worker statuses to match what supervisor is running', function () {
    $stoppedByHand = Worker::factory()->create(['server_id' => $this->server->id, 'status' => 'active']);
    $startedByHand = Worker::factory()->create(['server_id' => $this->server->id, 'status' => 'stopped']);
    $gaveUp = Worker::factory()->create(['server_id' => $this->server->id, 'status' => 'active']);
    $oneProcessCrashing = Worker::factory()->create(['server_id' => $this->server->id, 'status' => 'active', 'numprocs' => 2]);
    $recovered = Worker::factory()->create(['server_id' => $this->server->id, 'status' => 'crashed']);

    $output = <<<TXT
    flitops-worker-{$stoppedByHand->id}                 STOPPED   Nov 01 10:00 AM
    flitops-worker-{$startedByHand->id}                 RUNNING   pid 1201, uptime 0:03:10
    flitops-worker-{$gaveUp->id}                        FATAL     Exited too quickly (process log may have details)
    flitops-worker-{$oneProcessCrashing->id}:flitops-worker-{$oneProcessCrashing->id}_00   RUNNING   pid 1301, uptime 0:10:00
    flitops-worker-{$oneProcessCrashing->id}:flitops-worker-{$oneProcessCrashing->id}_01   BACKOFF   Exited too quickly (process log may have details)
    flitops-worker-{$recovered->id}                     RUNNING   pid 1401, uptime 0:00:40
    TXT;

    Event::fake([ServerProcessesUpdated::class]);

    (new SyncWorkerStatusesJob($this->server))->handle(supervisorStatusService($this->server, $output));

    expect($stoppedByHand->fresh()->status)->toBe('stopped');
    expect($startedByHand->fresh()->status)->toBe('active');
    expect($gaveUp->fresh()->status)->toBe('crashed');
    expect($oneProcessCrashing->fresh()->status)->toBe('crashed');
    expect($recovered->fresh()->status)->toBe('active');

    Event::assertDispatched(ServerProcessesUpdated::class, fn (ServerProcessesUpdated $event) => $event->serverId === $this->server->id);
});

it('leaves workers alone while flitops is changing them or when supervisor does not list them', function () {
    $restarting = Worker::factory()->create(['server_id' => $this->server->id, 'status' => 'restarting']);
    $unlisted = Worker::factory()->create(['server_id' => $this->server->id, 'status' => 'active']);

    $output = "flitops-worker-{$restarting->id}   STOPPED   Nov 01 10:00 AM\n";

    Event::fake([ServerProcessesUpdated::class]);

    (new SyncWorkerStatusesJob($this->server))->handle(supervisorStatusService($this->server, $output));

    expect($restarting->fresh()->status)->toBe('restarting');
    expect($unlisted->fresh()->status)->toBe('active');

    Event::assertNotDispatched(ServerProcessesUpdated::class);
});

it('keeps the saved statuses when the server cannot be reached', function () {
    $worker = Worker::factory()->create(['server_id' => $this->server->id, 'status' => 'active']);

    $sshService = mock(SshService::class)
        ->shouldReceive('connect')
        ->once()
        ->andThrow(new RuntimeException('Connection refused'))
        ->getMock();

    Event::fake([ServerProcessesUpdated::class]);

    (new SyncWorkerStatusesJob($this->server))->handle($sshService);

    expect($worker->fresh()->status)->toBe('active');

    Event::assertNotDispatched(ServerProcessesUpdated::class);
});
