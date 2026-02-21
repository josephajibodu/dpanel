<?php

use App\Actions\CronJob\DestroyCronJob;
use App\Enums\ServerStatus;
use App\Jobs\DestroyCronJobJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('removes cron file on server and deletes cron job', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $cronJob = CronJob::factory()->create([
        'server_id' => $server->id,
        'command' => 'to delete',
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

    $job = new DestroyCronJobJob($cronJob);
    $job->handle(app(DestroyCronJob::class));

    $this->assertDatabaseMissing('cron_jobs', ['id' => $cronJob->id]);
});
