<?php

use App\Actions\Worker\DestroyWorker;
use App\Enums\ServerStatus;
use App\Jobs\DestroyWorkerJob;
use App\Models\Server;
use App\Models\Worker;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('removes supervisor config on server and deletes worker', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $worker = Worker::factory()->create([
        'server_id' => $server->id,
        'name' => 'to_delete',
    ]);

    $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('sudo')->andReturn('');
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($mockConnection);

    $this->app->instance(SshService::class, $sshService);

    $job = new DestroyWorkerJob($worker);
    $job->handle(app(DestroyWorker::class));

    $this->assertDatabaseMissing('workers', ['id' => $worker->id]);
});
