<?php

use App\Enums\DeploymentStatus;
use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Enums\SiteStatus;
use App\Events\DeploymentOutput;
use App\Events\DeploymentStatusChanged;
use App\Events\ServerProcessesUpdated;
use App\Exceptions\DeploymentFailedException;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\DeployScript;
use App\Models\Server;
use App\Models\Site;
use App\Models\Worker;
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
            $command === 'mktemp -d' => '/tmp/tmp.fleetops',
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
    $connection->shouldReceive('exec')->andReturnUsing(fn (string $command) => $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '');
    $connection->shouldReceive('upload')->once()->andReturnUsing(function (string $content) use (&$uploaded) {
        $uploaded = $content;
    });
    $connection->shouldReceive('execWithOutput')->once()->andReturn(0);
    $connection->shouldReceive('disconnect')->andReturnNull();

    $job = new DeploySiteJob($deployment);
    $job->handle(deploySshServiceMock($connection));

    expect($uploaded)->toContain('CUSTOM_DEPLOY_MARKER')
        ->and($uploaded)->toContain("BRANCH='main'")
        ->and($uploaded)->toContain('RELEASE_PATH=')
        ->and($uploaded)->toContain('cd "$RELEASE_PATH"');
});

it('clones into a fresh release directory and symlinks shared paths', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $uploaded = null;
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnTrue();
    $connection->shouldReceive('exec')->andReturnUsing(fn (string $command) => $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '');
    $connection->shouldReceive('upload')->once()->andReturnUsing(function (string $content) use (&$uploaded) {
        $uploaded = $content;
    });
    $connection->shouldReceive('execWithOutput')->once()->andReturn(0);
    $connection->shouldReceive('disconnect')->andReturnNull();

    $job = new DeploySiteJob($deployment);
    $job->handle(deploySshServiceMock($connection));

    expect($uploaded)->toContain('git clone --branch main')
        ->and($uploaded)->toContain('"$RELEASE_PATH/.env"')
        ->and($uploaded)->toContain('"$RELEASE_PATH/storage"')
        ->and($uploaded)->toContain('ln -sfn "$RELEASE_PATH"');
});

/**
 * Run a deploy for the given site and return the script that was uploaded.
 */
function uploadedDeployScriptFor(Site $site): string
{
    $deployment = Deployment::factory()->pending()->forSite($site)->create();

    $uploaded = '';
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnTrue();
    $connection->shouldReceive('exec')->andReturnUsing(fn (string $command) => $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '');
    $connection->shouldReceive('upload')->once()->andReturnUsing(function (string $content) use (&$uploaded) {
        $uploaded = $content;
    });
    $connection->shouldReceive('execWithOutput')->once()->andReturn(0);
    $connection->shouldReceive('disconnect')->andReturnNull();

    (new DeploySiteJob($deployment))->handle(deploySshServiceMock($connection));

    return $uploaded;
}

it('builds, links shared files and goes live inside the app folder when the app is in a subfolder', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $this->site->update(['app_directory' => 'backend']);
    DeployScript::create(['site_id' => $this->site->id, 'script' => 'echo "app={{SITE_PATH}} web={{WEB_ROOT}}"']);

    $uploaded = uploadedDeployScriptFor($this->site);
    $release = $this->site->releasesPath().'/'.$this->site->deployments()->sole()->releaseFolderName();
    $sharedStorage = $this->site->sharedPath().'/storage';

    expect($uploaded)->toContain('git clone --branch main')
        ->and($uploaded)->toMatch('/ "\$RELEASE_PATH"\n.*\ncd "\$RELEASE_PATH\/backend"\n/s')
        ->and($uploaded)->toContain("ln -sfn '{$sharedStorage}' \"\$RELEASE_PATH/backend/storage\"")
        ->and($uploaded)->toContain("echo \"app={$release}/backend web={$release}/backend/public\"")
        ->and($uploaded)->toContain('chmod -R 775 "$RELEASE_PATH/backend/storage"')
        ->and($uploaded)->toContain("ln -sfn \"\$RELEASE_PATH/backend\" {$this->site->currentPath()}")
        ->and($uploaded)->toContain("cd \"\$RELEASE_PATH/backend\"\n\$PHP artisan queue:restart");
});

it('stops the deploy before linking when the shared env file is missing', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $uploaded = uploadedDeployScriptFor($this->site);
    $sharedEnv = $this->site->sharedPath().'/.env';

    expect($uploaded)->toContain("if [ ! -e '{$sharedEnv}' ]; then echo \"[ERROR] {$sharedEnv} is missing.")
        ->and($uploaded)->toContain('exit 1; fi');
});

it('replaces a directory the repository committed at a shared path instead of linking inside it', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $uploaded = uploadedDeployScriptFor($this->site);
    $sharedStorage = $this->site->sharedPath().'/storage';

    expect($uploaded)->toContain("rm -rf \"\$RELEASE_PATH/storage\" && ln -sfn '{$sharedStorage}' \"\$RELEASE_PATH/storage\"");
});

it('restarts queue workers, Horizon and Reverb only after current is swapped onto the new release', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    // A site with its own saved script (every site created before this change)
    // still gets the restarts, since they're appended by the deploy strategy.
    DeployScript::create([
        'site_id' => $this->site->id,
        'script' => 'echo "CUSTOM_DEPLOY_MARKER"',
    ]);

    $uploaded = uploadedDeployScriptFor($this->site);

    $swapPosition = strpos($uploaded, 'ln -sfn "$RELEASE_PATH" /home/');

    expect($swapPosition)->not->toBeFalse()
        ->and(strpos($uploaded, '$PHP artisan queue:restart'))->toBeGreaterThan($swapPosition)
        ->and(strpos($uploaded, '$PHP artisan horizon:terminate'))->toBeGreaterThan($swapPosition)
        ->and(strpos($uploaded, '$PHP artisan reverb:restart'))->toBeGreaterThan($swapPosition)
        ->and(strpos($uploaded, 'CUSTOM_DEPLOY_MARKER'))->toBeLessThan($swapPosition);
});

it('does not run artisan restarts after activating a non-laravel release', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $this->site->update(['project_type' => ProjectType::StaticHtml]);

    $uploaded = uploadedDeployScriptFor($this->site);

    expect($uploaded)->toContain('ln -sfn "$RELEASE_PATH"')
        ->and($uploaded)->not->toContain('queue:restart')
        ->and($uploaded)->not->toContain('horizon:terminate')
        ->and($uploaded)->not->toContain('reverb:restart');
});

it('prunes old releases after a successful deploy', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $lsCalled = false;
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnTrue();
    $connection->shouldReceive('upload')->andReturnNull();
    $connection->shouldReceive('execWithOutput')->andReturnUsing(function (string $command, callable $onOutput) {
        $onOutput('Done');

        return 0;
    });
    $connection->shouldReceive('disconnect')->andReturnNull();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$lsCalled) {
        if (str_contains($command, 'ls -1') && str_contains($command, '/releases')) {
            $lsCalled = true;
        }

        return match (true) {
            $command === 'mktemp -d' => '/tmp/tmp.fleetops',
            str_contains($command, 'git rev-parse HEAD') => 'a1b2c3d4e5f67890',
            default => '',
        };
    });

    $job = new DeploySiteJob($deployment);
    $job->handle(deploySshServiceMock($connection));

    expect($lsCalled)->toBeTrue();
});

it('finishes the deployment and logs a warning when old releases cannot be removed', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);
    config(['server.releases_to_keep' => 1]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnTrue();
    $connection->shouldReceive('upload')->andReturnNull();
    $connection->shouldReceive('execWithOutput')->andReturn(0);
    $connection->shouldReceive('disconnect')->andReturnNull();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) {
        return match (true) {
            $command === 'mktemp -d' => '/tmp/tmp.fleetops',
            str_contains($command, 'ls -1') => "01aaaaaaaaaaaaaaaaaaaaaaaa\n01bbbbbbbbbbbbbbbbbbbbbbbb",
            str_starts_with($command, 'rm -rf') && str_contains($command, '/releases/') => throw new RuntimeException('Permission denied'),
            default => '',
        };
    });

    (new DeploySiteJob($deployment))->handle(deploySshServiceMock($connection));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Finished);
    expect($deployment->logs()->where('type', 'error')->pluck('message')->all())
        ->toBe(['[WARNING] Could not remove old releases: Permission denied']);
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
    $connection->shouldReceive('exec')->andReturnUsing(fn (string $command) => $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '');
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

it('makes the uploaded deploy script self-delete and runs it by path', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $uploaded = null;
    $ranCommand = null;

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnTrue();
    $connection->shouldReceive('exec')->andReturnUsing(fn (string $command) => $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '');
    $connection->shouldReceive('upload')->once()->andReturnUsing(function (string $content) use (&$uploaded) {
        $uploaded = $content;
    });
    $connection->shouldReceive('execWithOutput')->once()->andReturnUsing(function (string $command) use (&$ranCommand) {
        $ranCommand = $command;

        return 0;
    });
    $connection->shouldReceive('disconnect')->andReturnNull();

    $job = new DeploySiteJob($deployment);
    $job->handle(deploySshServiceMock($connection));

    // The script lives in the private directory and deletes itself first,
    // ahead of the deploy body.
    expect($uploaded)->toContain("rm -f '/tmp/tmp.fleetops/deploy.sh'")
        ->and(strpos($uploaded, 'rm -f'))->toBeLessThan(strpos($uploaded, 'cd "$RELEASE_PATH"'));

    // The run command is just the script path — no fragile trailing cleanup chain.
    expect($ranCommand)->toBe("bash '/tmp/tmp.fleetops/deploy.sh'");
});

it('removes the private script directory even when the run throws', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $execCommands = [];

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('directoryExists')->andReturnTrue();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$execCommands) {
        $execCommands[] = $command;

        return $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '';
    });
    $connection->shouldReceive('upload')->once()->andReturnNull();
    $connection->shouldReceive('execWithOutput')->once()->andThrow(new RuntimeException('SSH timed out'));
    $connection->shouldReceive('disconnect')->andReturnNull();

    $job = new DeploySiteJob($deployment);

    expect(fn () => $job->handle(deploySshServiceMock($connection)))
        ->toThrow(RuntimeException::class, 'SSH timed out');

    expect($execCommands)->toContain("rm -rf '/tmp/tmp.fleetops'");
});

it('restarts the site workers marked to restart after a deploy, but not stopped ones', function () {
    $running = Worker::factory()->forSite($this->site)->create(['status' => 'active', 'restart_on_deploy' => true]);
    $crashed = Worker::factory()->forSite($this->site)->create(['status' => 'crashed', 'restart_on_deploy' => true]);
    $stopped = Worker::factory()->forSite($this->site)->create(['status' => 'stopped', 'restart_on_deploy' => true]);
    $notMarked = Worker::factory()->forSite($this->site)->create(['status' => 'active', 'restart_on_deploy' => false]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $sudoCommands = [];
    $connection = deployConnectionMock();
    $connection->shouldReceive('sudo')->andReturnUsing(function (string $command) use (&$sudoCommands) {
        $sudoCommands[] = $command;

        return '';
    });

    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class, ServerProcessesUpdated::class]);

    (new DeploySiteJob($deployment))->handle(deploySshServiceMock($connection));

    expect($sudoCommands)->toBe([
        "supervisorctl signal TERM flitops-worker-{$running->id}:*",
        "supervisorctl start flitops-worker-{$running->id}:*",
        "supervisorctl signal TERM flitops-worker-{$crashed->id}:*",
        "supervisorctl start flitops-worker-{$crashed->id}:*",
    ]);
    expect($crashed->fresh()->status)->toBe('active');
    expect($stopped->fresh()->status)->toBe('stopped');

    Event::assertDispatched(ServerProcessesUpdated::class, fn (ServerProcessesUpdated $event) => $event->serverId === $this->server->id);
});

it('fully restarts a worker with auto restart off after a deploy, because it would not come back after exiting', function () {
    $worker = Worker::factory()->forSite($this->site)->create(['status' => 'active', 'restart_on_deploy' => true, 'auto_restart' => false]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $sudoCommands = [];
    $connection = deployConnectionMock();
    $connection->shouldReceive('sudo')->andReturnUsing(function (string $command) use (&$sudoCommands) {
        $sudoCommands[] = $command;

        return '';
    });

    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class, ServerProcessesUpdated::class]);

    (new DeploySiteJob($deployment))->handle(deploySshServiceMock($connection));

    expect($sudoCommands)->toBe(["supervisorctl restart flitops-worker-{$worker->id}:*"]);
});

it('finishes the deployment and logs a warning when a worker cannot be restarted', function () {
    $worker = Worker::factory()->forSite($this->site)->create(['name' => 'queue', 'status' => 'active', 'restart_on_deploy' => true]);

    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $connection = deployConnectionMock();
    $connection->shouldReceive('sudo')->andThrow(new RuntimeException('no such process'));

    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class, ServerProcessesUpdated::class]);

    (new DeploySiteJob($deployment))->handle(deploySshServiceMock($connection));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Finished);
    expect($deployment->logs()->where('type', 'error')->pluck('message')->all())
        ->toBe(['[WARNING] Could not restart worker "queue": no such process']);
});
