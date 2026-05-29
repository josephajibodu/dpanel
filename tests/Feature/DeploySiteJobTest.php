<?php

use App\Enums\DeploymentStatus;
use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Enums\SiteStatus;
use App\Events\DeploymentOutput;
use App\Events\DeploymentStatusChanged;
use App\Exceptions\DeploymentFailedException;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\DeployScript;
use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::Active]);
    $this->site = Site::factory()->forServer($this->server)->create([
        'domain' => 'example.com',
        'repository' => 'acme/app',
        'branch' => 'main',
        'project_type' => ProjectType::Laravel,
        'status' => SiteStatus::Provisioned,
    ]);
});

/**
 * A mock SSH connection wired for a healthy git repository and deploy run.
 *
 * @param  list<string>  $outputLines
 */
function deployConnectionMock(int $exitCode = 0, array $outputLines = ['Pulling latest changes...', 'Build complete']): SshConnection
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();

    $connection->shouldReceive('directoryExists')->andReturnTrue();

    $connection->shouldReceive('exec')->andReturnUsing(function (string $command, int $timeout = 30) {
        return match (true) {
            str_contains($command, 'git rev-parse HEAD') => 'a1b2c3d4e5f67890',
            str_contains($command, "--format='%s'") => 'Add deployment job tests',
            str_contains($command, "--format='%an'") => 'Jane Developer',
            default => '',
        };
    });

    $connection->shouldReceive('upload')->andReturnNull();

    $connection->shouldReceive('execWithOutput')->andReturnUsing(
        function (string $command, callable $onOutput, int $timeout) use ($exitCode, $outputLines) {
            foreach ($outputLines as $line) {
                $onOutput($line);
            }

            return $exitCode;
        }
    );

    $connection->shouldReceive('disconnect')->andReturnNull();

    return $connection;
}

function deploySshServiceMock(SshConnection $connection): SshService
{
    $service = Mockery::mock(SshService::class);
    $service->shouldReceive('connect')->once()->andReturn($connection);

    return $service;
}

it('runs a full deployment and marks it finished', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $job = new DeploySiteJob($deployment);
    $job->handle(deploySshServiceMock(deployConnectionMock(outputLines: ['Pulling...', 'Done'])));

    $deployment->refresh();
    $this->site->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($deployment->started_at)->not->toBeNull()
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($deployment->duration_seconds)->not->toBeNull()
        ->and($deployment->commit_hash)->toBe('a1b2c3d4e5f67890')
        ->and($deployment->commit_message)->toBe('Add deployment job tests')
        ->and($deployment->commit_author)->toBe('Jane Developer')
        ->and($this->site->status)->toBe(SiteStatus::Deployed)
        ->and($this->site->deployment_finished_at)->not->toBeNull();

    expect($deployment->logs()->where('type', 'output')->pluck('message')->all())
        ->toContain('Pulling...', 'Done');

    expect($deployment->logs()->where('type', 'success')->exists())->toBeTrue();

    Event::assertDispatched(DeploymentStatusChanged::class, fn (DeploymentStatusChanged $e) => $e->event === 'started');
    Event::assertDispatched(DeploymentStatusChanged::class, fn (DeploymentStatusChanged $e) => $e->event === 'finished');
});

it('skips the deploy script when no repository is connected and marks the site provisioned', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $this->site->update(['repository' => null]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create([
        'commit_hash' => null,
        'commit_message' => null,
        'commit_author' => null,
    ]);

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnFalse();
    $connection->shouldReceive('execWithOutput')->never();
    $connection->shouldReceive('upload')->never();
    $connection->shouldReceive('disconnect')->andReturnNull();

    $job = new DeploySiteJob($deployment);
    $job->handle(deploySshServiceMock($connection));

    $deployment->refresh();
    $this->site->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($deployment->commit_hash)->toBeNull()
        ->and($this->site->status)->toBe(SiteStatus::Provisioned);

    expect($deployment->logs()->where('message', 'No repository connected — deployment skipped.')->exists())
        ->toBeTrue();
});

it('uploads the site custom deploy script when one is defined', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    DeployScript::create([
        'site_id' => $this->site->id,
        'script' => 'echo "CUSTOM_DEPLOY_MARKER"',
    ]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $uploaded = null;
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnTrue();
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('upload')->once()->andReturnUsing(function (string $content) use (&$uploaded) {
        $uploaded = $content;
    });
    $connection->shouldReceive('execWithOutput')->once()->andReturn(0);
    $connection->shouldReceive('disconnect')->andReturnNull();

    $job = new DeploySiteJob($deployment);
    $job->handle(deploySshServiceMock($connection));

    expect($uploaded)->toContain('CUSTOM_DEPLOY_MARKER')
        ->and($uploaded)->toContain("BRANCH='main'")
        ->and($uploaded)->toContain('cd $SITE_ROOT');
});

it('marks the deployment and site failed when the deploy script exits non-zero', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $job = new DeploySiteJob($deployment);
    $service = deploySshServiceMock(deployConnectionMock(exitCode: 1, outputLines: ['Building...', 'error: boom']));

    expect(fn () => $job->handle($service))->toThrow(DeploymentFailedException::class);

    $deployment->refresh();
    $this->site->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($this->site->status)->toBe(SiteStatus::Failed);

    expect($deployment->logs()->where('type', 'error')->exists())->toBeTrue();

    Event::assertDispatched(DeploymentStatusChanged::class, fn (DeploymentStatusChanged $e) => $e->event === 'failed');
});

it('marks the deployment failed when the ssh connection cannot be established', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $service = Mockery::mock(SshService::class);
    $service->shouldReceive('connect')->once()->andThrow(new RuntimeException('SSH unreachable'));

    $job = new DeploySiteJob($deployment);

    expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'SSH unreachable');

    $deployment->refresh();
    $this->site->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($this->site->status)->toBe(SiteStatus::Failed);
});

it('marks the deployment failed via the failed() hook when the job fails entirely', function () {
    Event::fake([DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->running()->forSite($this->site)->create();

    $job = new DeploySiteJob($deployment);
    $job->failed(new RuntimeException('worker timed out'));

    $deployment->refresh();
    $this->site->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($this->site->status)->toBe(SiteStatus::Failed);

    Event::assertDispatched(DeploymentStatusChanged::class, fn (DeploymentStatusChanged $e) => $e->event === 'failed');
});

it('completes the deployment without commit metadata when no git directory exists', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create([
        'commit_hash' => null,
        'commit_message' => null,
        'commit_author' => null,
    ]);

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnFalse();
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('upload')->andReturnNull();
    $connection->shouldReceive('execWithOutput')->once()->andReturn(0);
    $connection->shouldReceive('disconnect')->andReturnNull();

    $job = new DeploySiteJob($deployment);
    $job->handle(deploySshServiceMock($connection));

    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($deployment->commit_hash)->toBeNull()
        ->and($deployment->commit_message)->toBeNull();
});
