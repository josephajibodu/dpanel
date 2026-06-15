<?php

use App\Actions\CronJob\CreateCronJob;
use App\Actions\Worker\CreateWorker;
use App\Events\ServerProcessesUpdated;
use App\Jobs\CreateCronJobJob;
use App\Jobs\CreateWorkerJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => \App\Enums\ServerStatus::Active,
    ]);
});

it('fires ServerProcessesUpdated when a worker job runs', function () {
    Event::fake([ServerProcessesUpdated::class]);

    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
    ]);

    // Stub the action so we don't actually SSH; the finally block fires the event regardless.
    $action = mock(CreateWorker::class);
    $action->shouldReceive('execute')->once()->with(\Mockery::on(fn ($w) => $w->id === $worker->id));

    (new CreateWorkerJob($worker))->handle($action);

    Event::assertDispatched(
        ServerProcessesUpdated::class,
        fn (ServerProcessesUpdated $event) => $event->serverId === $this->server->id
    );
});

it('fires ServerProcessesUpdated even when a worker job throws', function () {
    Event::fake([ServerProcessesUpdated::class]);

    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
    ]);

    $action = mock(CreateWorker::class);
    $action->shouldReceive('execute')->once()->andThrow(new \RuntimeException('boom'));

    try {
        (new CreateWorkerJob($worker))->handle($action);
    } catch (\RuntimeException) {
        // expected
    }

    Event::assertDispatched(ServerProcessesUpdated::class);
});

it('fires ServerProcessesUpdated when a cron job runs', function () {
    Event::fake([ServerProcessesUpdated::class]);

    $cron = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
    ]);

    $action = mock(CreateCronJob::class);
    $action->shouldReceive('execute')->once();

    (new CreateCronJobJob($cron))->handle($action);

    Event::assertDispatched(
        ServerProcessesUpdated::class,
        fn (ServerProcessesUpdated $event) => $event->serverId === $this->server->id
    );
});

it('broadcasts on the server channel as server.processes.updated', function () {
    $event = new ServerProcessesUpdated(serverId: 42);

    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0]->name)->toBe('private-server.42')
        ->and($event->broadcastAs())->toBe('server.processes.updated');
});
