<?php

use App\Actions\Sites\RunSiteCommand;
use App\Enums\ServerStatus;
use App\Enums\SiteCommandRunStatus;
use App\Jobs\RunSiteCommandJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCommandRun;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs command on server and updates run with output and completed status', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'domain' => 'example.com',
    ]);
    $run = SiteCommandRun::factory()->forSite($site)->pending()->create([
        'command' => 'php artisan --version',
    ]);

    $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('exec')
        ->once()
        ->with(\Mockery::on(function (string $cmd) use ($site) {
            return str_contains($cmd, $site->rootPath())
                && str_contains($cmd, 'php artisan --version');
        }), 300)
        ->andReturn('Laravel Framework 12.x');
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($mockConnection);

    $this->app->instance(SshService::class, $sshService);

    $job = new RunSiteCommandJob($run);
    $job->handle(app(RunSiteCommand::class));

    $run->refresh();
    expect($run->status)->toBe(SiteCommandRunStatus::Completed)
        ->and($run->output)->toBe('Laravel Framework 12.x')
        ->and($run->exit_code)->toBe(0)
        ->and($run->finished_at)->not->toBeNull();
});

it('sets run to failed when command exits non-zero', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $run = SiteCommandRun::factory()->forSite($site)->pending()->create([
        'command' => 'false',
    ]);

    $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('exec')->andThrow(
        new \App\Exceptions\SshCommandException(
            command: 'cd /path && false 2>&1',
            exitCode: 1,
            output: 'some stderr',
            stderr: null,
        )
    );
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($mockConnection);

    $this->app->instance(SshService::class, $sshService);

    $job = new RunSiteCommandJob($run);
    $job->handle(app(RunSiteCommand::class));

    $run->refresh();
    expect($run->status)->toBe(SiteCommandRunStatus::Failed)
        ->and($run->exit_code)->toBe(1);
});

it('sets run to failed when execute throws', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $run = SiteCommandRun::factory()->forSite($site)->pending()->create();

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andThrow(new \RuntimeException('SSH failed'));

    $this->app->instance(SshService::class, $sshService);

    $job = new RunSiteCommandJob($run);
    try {
        $job->handle(app(RunSiteCommand::class));
    } catch (\Throwable) {
        //
    }

    $run->refresh();
    expect($run->status)->toBe(SiteCommandRunStatus::Failed);
});
