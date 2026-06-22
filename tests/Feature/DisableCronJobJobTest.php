<?php

use App\Actions\CronJob\DisableCronJob;
use App\Enums\ServerStatus;
use App\Jobs\DisableCronJobJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('removes cron file on server when disabled', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $cronJob = CronJob::factory()->create([
        'server_id' => $server->id,
        'status' => 'active',
        'hidden' => true,
    ]);

    $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('sudo')->andReturn('');
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($mockConnection);

    app()->instance(SshService::class, $sshService);

    $job = new DisableCronJobJob($cronJob);
    $job->handle(app(DisableCronJob::class));

    $cronJob->refresh();
    expect($cronJob->status)->toBe('active');
});

it('sets status to failed and rethrows when SSH fails', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $cronJob = CronJob::factory()->create([
        'server_id' => $server->id,
        'status' => 'active',
        'hidden' => true,
    ]);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->andThrow(new \RuntimeException('SSH connection refused'));

    app()->instance(SshService::class, $sshService);

    expect(fn () => (new DisableCronJobJob($cronJob))->handle(app(DisableCronJob::class)))
        ->toThrow(\RuntimeException::class, 'SSH connection refused');

    $cronJob->refresh();
    expect($cronJob->status)->toBe('failed');
});
