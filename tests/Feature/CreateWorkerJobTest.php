<?php

use App\Actions\Worker\CreateWorker;
use App\Enums\ServerStatus;
use App\Jobs\CreateWorkerJob;
use App\Models\Server;
use App\Models\Worker;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs action and sets worker status to active when auto_start is true', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $worker = Worker::factory()->create([
        'server_id' => $server->id,
        'status' => 'pending',
        'auto_start' => true,
    ]);

    $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('upload')->andReturn(null);
    $mockConnection->shouldReceive('sudo')->andReturn('');
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($mockConnection);

    $this->app->instance(SshService::class, $sshService);

    $job = new CreateWorkerJob($worker);
    $job->handle(app(CreateWorker::class));

    $worker->refresh();
    expect($worker->status)->toBe('active');
});

it('runs action and sets worker status to stopped when auto_start is false', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $worker = Worker::factory()->create([
        'server_id' => $server->id,
        'status' => 'pending',
        'auto_start' => false,
    ]);

    $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('upload')->andReturn(null);
    $mockConnection->shouldReceive('sudo')->andReturn('');
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($mockConnection);

    $this->app->instance(SshService::class, $sshService);

    $job = new CreateWorkerJob($worker);
    $job->handle(app(CreateWorker::class));

    $worker->refresh();
    expect($worker->status)->toBe('stopped');
});

it('sets worker status to failed when execute throws', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $worker = Worker::factory()->create([
        'server_id' => $server->id,
        'status' => 'pending',
    ]);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andThrow(new \RuntimeException('SSH failed'));

    $this->app->instance(SshService::class, $sshService);

    $job = new CreateWorkerJob($worker);
    try {
        $job->handle(app(CreateWorker::class));
    } catch (\Throwable) {
        //
    }

    $worker->refresh();
    expect($worker->status)->toBe('failed');
});
