<?php

use App\Actions\CronJob\CreateCronJob;
use App\Enums\ServerStatus;
use App\Jobs\CreateCronJobJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes cron file on server and sets status to active', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $cronJob = CronJob::factory()->create([
        'server_id' => $server->id,
        'status' => 'pending',
        'hidden' => false,
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

    $job = new CreateCronJobJob($cronJob);
    $job->handle(app(CreateCronJob::class));

    $cronJob->refresh();
    expect($cronJob->status)->toBe('active');
});

it('sets cron job status to failed when execute throws', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $cronJob = CronJob::factory()->create([
        'server_id' => $server->id,
        'status' => 'pending',
    ]);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andThrow(new \RuntimeException('SSH failed'));

    $this->app->instance(SshService::class, $sshService);

    $job = new CreateCronJobJob($cronJob);
    try {
        $job->handle(app(CreateCronJob::class));
    } catch (\Throwable) {
        //
    }

    $cronJob->refresh();
    expect($cronJob->status)->toBe('failed');
});
