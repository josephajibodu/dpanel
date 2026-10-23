<?php

use App\Actions\Backups\RunSqliteBackup;
use App\Actions\Sites\CleanupSiteExternalResourcesAction;
use App\Actions\Sites\ProvisionSiteAction;
use App\Actions\Sites\RunSiteCommand;
use App\Enums\ConnectionStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Enums\ServiceType;
use App\Enums\SiteStatus;
use App\Events\DeploymentOutput;
use App\Events\DeploymentStatusChanged;
use App\Events\ServerSitesUpdated;
use App\Jobs\CreateSiteJob;
use App\Jobs\DeleteSiteJob;
use App\Jobs\DeploySiteJob;
use App\Jobs\MoveSitePhpPoolJob;
use App\Jobs\RollbackSiteJob;
use App\Jobs\SyncEnvironmentJob;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Backup;
use App\Models\CronJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCommandRun;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\SiteProvisioning\SiteIsolationService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A fake SSH connection that records every command (exec and streamed) in
 * order, and every upload by path.
 *
 * @param  array<int, string>  $commands
 * @param  array<string, string>  $uploads
 * @param  string  $existingUserComment  What `getent passwd` reports for the site user ('' = no such user).
 */
function isolationConnection(array &$commands, array &$uploads = [], string $existingUserComment = ''): SshConnection
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $existingUserComment) {
        $commands[] = $command;

        return match (true) {
            $command === 'mktemp -d' => '/tmp/tmp.fleetops',
            str_contains($command, 'nginx -t') => 'syntax is ok',
            str_starts_with($command, 'getent passwd') => $existingUserComment,
            str_contains($command, 'test -d') => 'yes',
            default => '',
        };
    });
    $connection->shouldReceive('execWithOutput')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return 0;
    });
    $connection->shouldReceive('upload')->andReturnUsing(function (string $content, string $path) use (&$uploads) {
        $uploads[$path] = $content;
    });
    $connection->shouldReceive('disconnect')->andReturnNull();

    return $connection;
}

function bindIsolationSsh(SshConnection $connection): void
{
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
        'connection_status' => ConnectionStatus::Successful,
    ]);
});

// --------------------------------------------------------------------------
// Creating a site
// --------------------------------------------------------------------------

it('isolates new sites by default', function () {
    Queue::fake([CreateSiteJob::class]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'example.com',
            'project_type' => 'laravel',
        ])
        ->assertRedirect();

    $site = Site::query()->where('domain', 'example.com')->sole();

    expect($site->system_user)->toBe('example_com')
        ->and($site->isIsolated())->toBeTrue()
        ->and($site->rootPath())->toBe('/home/example_com/example.com');
});

it('keeps an unisolated site on the server user when the box is unticked', function () {
    Queue::fake([CreateSiteJob::class]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'example.com',
            'project_type' => 'laravel',
            'isolated' => false,
        ])
        ->assertRedirect();

    $site = Site::query()->where('domain', 'example.com')->sole();

    expect($site->system_user)->toBeNull()
        ->and($site->systemUser())->toBe('artisan')
        ->and($site->rootPath())->toBe('/home/artisan/example.com');
});

// --------------------------------------------------------------------------
// Provisioning
// --------------------------------------------------------------------------

function provisionIsolationSite(Site $site, array &$commands, array &$uploads, string $existingUserComment = ''): void
{
    Event::fake([ServerSitesUpdated::class]);
    Queue::fake([DeploySiteJob::class]);

    bindIsolationSsh(isolationConnection($commands, $uploads, $existingUserComment));

    try {
        app(ProvisionSiteAction::class)->execute($site);
    } catch (Throwable) {
        // The site's status and error message carry the outcome.
    }
}

it('creates the site user and its own php-fpm pool, and points nginx at it', function () {
    $site = Site::factory()->forServer($this->server)->pending()->isolated('example_com')->create([
        'domain' => 'example.com',
        'root_path' => 'example.com',
        'repository' => null,
        'source_control_account_id' => null,
        'project_type' => ProjectType::Laravel,
        'php_version' => '8.3',
    ]);

    $commands = [];
    $uploads = [];
    provisionIsolationSite($site, $commands, $uploads);

    expect($site->fresh()->status)->toBe(SiteStatus::Provisioned);

    $all = implode("\n", $commands);

    expect($all)->toContain("sudo useradd --create-home --home-dir '/home/example_com' --shell /bin/bash --user-group --comment 'flitops-site {$site->ulid}' 'example_com'")
        ->and($all)->toContain("sudo chmod 750 '/home/example_com'")
        ->and($all)->toContain("sudo usermod -aG 'example_com' 'www-data'")
        ->and($all)->not->toContain('sudoers')
        ->and($all)->not->toContain('usermod -aG sudo');

    expect($uploads['/tmp/tmp.fleetops/pool.conf'])->toContain('[example_com]')
        ->toContain('user = example_com')
        ->toContain('group = example_com')
        ->toContain('listen = /run/php/php8.3-fpm-example_com.sock')
        ->toContain('listen.owner = www-data')
        ->toContain('pm = ondemand');

    expect($all)->toContain("sudo install -o root -g root -m 644 '/tmp/tmp.fleetops/pool.conf' '/etc/php/8.3/fpm/pool.d/example_com.conf'")
        ->and($all)->toContain("sudo 'php-fpm8.3' -t")
        ->and($all)->toContain("sudo systemctl reload 'php8.3-fpm'");

    expect($all)->toContain('fastcgi_pass unix:/run/php/php8.3-fpm-example_com.sock;')
        ->and($all)->not->toContain('php8.3-fpm-artisan.sock');

    // Site files are created by the site's own user, never chowned to the server user.
    expect(collect($commands)->contains(fn ($c) => str_starts_with($c, "sudo -u 'example_com' -H bash -c 'mkdir -p /home/example_com/example.com/releases")))->toBeTrue()
        ->and($all)->not->toContain('chown -R artisan')
        ->and($all)->not->toContain('/home/artisan/example.com')
        ->and($all)->toContain('chmod 600 /home/example_com/example.com/shared/.env');
});

it('provisions an unisolated site exactly as before', function () {
    $site = Site::factory()->forServer($this->server)->pending()->create([
        'domain' => 'example.com',
        'root_path' => 'example.com',
        'repository' => null,
        'source_control_account_id' => null,
        'project_type' => ProjectType::Laravel,
        'php_version' => '8.3',
    ]);

    $commands = [];
    $uploads = [];
    provisionIsolationSite($site, $commands, $uploads);

    $all = implode("\n", $commands);

    expect($site->fresh()->status)->toBe(SiteStatus::Provisioned)
        ->and($uploads)->toBe([])
        ->and($all)->not->toContain('useradd')
        ->and($all)->not->toContain('sudo -u')
        ->and($all)->toContain('mkdir -p /home/artisan/example.com/releases /home/artisan/example.com/shared')
        ->and($all)->toContain('sudo chown -R artisan:www-data /home/artisan/example.com')
        ->and($all)->toContain('sudo chmod 640 /home/artisan/example.com/shared/.env')
        ->and($all)->toContain('fastcgi_pass unix:/run/php/php8.3-fpm-artisan.sock;');
});

it('refuses to take over an existing Linux user it did not create for the site', function () {
    $site = Site::factory()->forServer($this->server)->pending()->isolated('example_com')->create([
        'repository' => null,
        'source_control_account_id' => null,
    ]);

    $commands = [];
    $uploads = [];
    provisionIsolationSite($site, $commands, $uploads, existingUserComment: 'Someone Else');

    $site->refresh();

    expect($site->status)->toBe(SiteStatus::Failed)
        ->and($site->error_message)->toContain('already exists')
        ->and(implode("\n", $commands))->not->toContain('useradd')
        ->and(implode("\n", $commands))->not->toContain('usermod');
});

it('reuses the site user when retrying the same site', function () {
    $site = Site::factory()->forServer($this->server)->pending()->isolated('example_com')->create([
        'repository' => null,
        'source_control_account_id' => null,
    ]);

    $commands = [];
    $uploads = [];
    provisionIsolationSite($site, $commands, $uploads, existingUserComment: SiteIsolationService::accountComment($site->ulid));

    expect($site->fresh()->status)->toBe(SiteStatus::Provisioned)
        ->and(implode("\n", $commands))->not->toContain('useradd');
});

it('removes a pool that fails the php-fpm config check instead of reloading', function () {
    $site = Site::factory()->forServer($this->server)->isolated('example_com')->create(['php_version' => '8.3']);

    $commands = [];
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        if (str_contains($command, '-t 2>&1')) {
            throw new RuntimeException('bad pool');
        }

        return $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '';
    });
    $connection->shouldReceive('upload');

    expect(fn () => app(SiteIsolationService::class)->writePool($connection, $site, '8.3'))->toThrow(RuntimeException::class);

    expect($commands)->toContain("sudo rm -f '/etc/php/8.3/fpm/pool.d/example_com.conf'")
        ->and(implode("\n", $commands))->not->toContain('systemctl reload');
});

// --------------------------------------------------------------------------
// Deploys, rollbacks, environment and commands
// --------------------------------------------------------------------------

function isolatedLaravelSite(Server $server): Site
{
    return Site::factory()->forServer($server)->isolated('example_com')->create([
        'domain' => 'example.com',
        'root_path' => 'example.com',
        'repository' => 'acme/app',
        'branch' => 'main',
        'project_type' => ProjectType::Laravel,
        'php_version' => '8.3',
        'status' => SiteStatus::Provisioned,
    ]);
}

it('clones as the server user and runs the deploy script as the site user', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $site = isolatedLaravelSite($this->server);
    $deployment = Deployment::factory()->pending()->forSite($site)->create();

    $commands = [];
    $uploads = [];
    $connection = isolationConnection($commands, $uploads);
    bindIsolationSsh($connection);

    (new DeploySiteJob($deployment))->handle(app(SshService::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Finished);

    $clone = collect($commands)->first(fn ($c) => str_contains($c, 'git clone'));
    $run = collect($commands)->first(fn ($c) => str_starts_with($c, "cat '/tmp/tmp.fleetops/deploy.sh' | "));
    $reload = collect($commands)->first(fn ($c) => str_contains($c, 'service "$PHP_FPM" reload'));

    // The clone uses the server user's key and hands the files to the site user.
    expect($clone)->toContain('ssh -i /home/artisan/.ssh/id_ed25519')
        ->and($clone)->toContain('CLONE_DIR=$(mktemp -d)')
        ->and($clone)->toContain("| sudo -u 'example_com' -H bash -c 'mkdir -p \"\$1\" && tar -C \"\$1\" -xf -' _ \"\$RELEASE_PATH\"")
        ->and($clone)->not->toStartWith('sudo -u');

    // The deploy script runs as the site user, without sudo or a git clone.
    $script = $uploads['/tmp/tmp.fleetops/deploy.sh'];
    expect($run)->toContain("| sudo -u 'example_com' -H bash -c")
        ->and($script)->toContain("RELEASE_PATH='/home/example_com/example.com/releases/")
        ->and($script)->toContain('$COMPOSER install')
        ->and($script)->toContain('ln -sfn "$RELEASE_PATH" /home/example_com/example.com/current')
        ->and($script)->not->toContain('sudo')
        ->and($script)->not->toContain('git clone');

    // PHP-FPM is reloaded by the server user after the script succeeds.
    expect($reload)->not->toBeNull()
        ->and($reload)->not->toContain('sudo -u')
        ->and(array_search($reload, $commands, true))->toBeGreaterThan(array_search($run, $commands, true));

    // Release housekeeping happens as the site user.
    expect(collect($commands)->contains(fn ($c) => str_starts_with($c, "sudo -u 'example_com' -H bash -c 'ls -1 /home/example_com/example.com/releases")))->toBeTrue()
        ->and(collect($commands)->contains(fn ($c) => str_starts_with($c, "sudo -u 'example_com' -H bash -c 'cd /home/example_com/example.com/releases/") && str_contains($c, 'git rev-parse HEAD')))->toBeTrue();
});

it('stops before running the deploy script when the clone fails', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $site = isolatedLaravelSite($this->server);
    $deployment = Deployment::factory()->pending()->forSite($site)->create();

    $commands = [];
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return '';
    });
    $connection->shouldReceive('execWithOutput')->once()->andReturn(128);
    $connection->shouldReceive('upload')->never();
    $connection->shouldReceive('disconnect')->andReturnNull();
    bindIsolationSsh($connection);

    expect(fn () => (new DeploySiteJob($deployment))->handle(app(SshService::class)))->toThrow(Exception::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(implode("\n", $commands))->not->toContain('reload');
});

it('rolls back as the site user and reloads php-fpm as the server user', function () {
    Event::fake([DeploymentOutput::class, DeploymentStatusChanged::class]);

    $site = isolatedLaravelSite($this->server);
    $target = Deployment::factory()->finished()->forSite($site)->create();
    $rollback = Deployment::factory()->pending()->forSite($site)->create([
        'triggered_by' => 'rollback',
        'rollback_of_deployment_id' => $target->id,
        'release_path' => $target->ulid,
    ]);

    $commands = [];
    bindIsolationSsh(isolationConnection($commands));

    (new RollbackSiteJob($rollback))->handle(app(SshService::class), app(DeploymentStrategy::class));

    expect($rollback->fresh()->status)->toBe(DeploymentStatus::Finished);

    expect($commands[0])->toStartWith("sudo -u 'example_com' -H bash -c 'test -d '")
        ->and($commands[1])->toStartWith("sudo -u 'example_com' -H bash -c")
        ->and($commands[1])->toContain('ln -sfn')
        ->and($commands[1])->toContain($target->ulid)
        ->and($commands[1])->not->toContain('service "$PHP_FPM" reload')
        ->and($commands[2])->toContain('sudo -S flock -w 10 /run/flitops-php-fpm.lock service "$PHP_FPM" reload')
        ->and($commands[2])->not->toContain('sudo -u');
});

it('writes the environment file as the site user, readable only by it', function () {
    $site = isolatedLaravelSite($this->server);
    $site->update(['env_content' => "APP_KEY=secret\n"]);

    $commands = [];
    $uploads = [];
    bindIsolationSsh(isolationConnection($commands, $uploads));

    (new SyncEnvironmentJob($site, clearConfigCache: true))->handle(app(SshService::class));

    $envPath = '/home/example_com/example.com/shared/.env';

    expect($uploads)->toBe(['/tmp/tmp.fleetops/.env' => "APP_KEY=secret\n"])
        ->and($commands)->toContain("cat '/tmp/tmp.fleetops/.env' | sudo -u 'example_com' -H bash -c 'umask 077 && cat > '\\''{$envPath}.flitops-tmp'\\'' && mv -f '\\''{$envPath}.flitops-tmp'\\'' '\\''{$envPath}'\\'''")
        ->and($commands)->toContain("rm -rf '/tmp/tmp.fleetops'")
        ->and(implode("\n", $commands))->not->toContain('chown')
        ->and(collect($commands)->contains(fn ($c) => str_starts_with($c, "sudo -u 'example_com'") && str_contains($c, 'artisan config:cache')))->toBeTrue();
});

it('runs site commands as the site user', function () {
    $site = isolatedLaravelSite($this->server);
    $run = SiteCommandRun::factory()->forSite($site)->pending()->create(['command' => 'php artisan about']);

    $commands = [];
    bindIsolationSsh(isolationConnection($commands));

    app(RunSiteCommand::class)->execute($run);

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toStartWith("sudo -u 'example_com' -H bash -c 'cd /home/example_com/example.com/current && bash -lc ");
});

it('snapshots an isolated sqlite database as the site user and uploads it as the server user', function () {
    $site = isolatedLaravelSite($this->server);
    $site->update(['server_database_id' => null]);
    $storageProvider = StorageProvider::factory()->create();

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('bucket')->andReturn('test-bucket');
    $driver->shouldReceive('awsCredentialsFile')->andReturn("[default]\naws_access_key_id = x\n");
    $driver->shouldReceive('awsCliArgs')->andReturn("--region='auto'");
    $driver->shouldReceive('size')->andReturn(4096);
    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);

    $commands = [];
    bindIsolationSsh(isolationConnection($commands));

    $backup = Backup::factory()->forSite($site)->create(['storage_provider_id' => $storageProvider->id]);
    app(RunSqliteBackup::class)->execute($backup);

    $snapshot = collect($commands)->first(fn ($c) => str_contains($c, 'VACUUM INTO'));
    $siteUserPart = str($snapshot)->after("sudo -u '\\''example_com'\\''")->before(' | AWS_SHARED_CREDENTIALS_FILE')->toString();

    expect($backup->fresh()->status)->toBe('completed')
        ->and($siteUserPart)->toContain('VACUUM INTO')
        ->and($siteUserPart)->toContain('/home/example_com/example.com/shared/database/database.sqlite')
        ->and($siteUserPart)->not->toContain('aws')
        ->and($snapshot)->toContain('aws s3 cp - ');
});

// --------------------------------------------------------------------------
// Workers and cron jobs
// --------------------------------------------------------------------------

it('requires a job tied to an isolated site to run as that site user', function (string $resource, string $user, bool $allowed) {
    Queue::fake();

    $site = isolatedLaravelSite($this->server);

    $payload = $resource === 'workers'
        ? ['name' => 'queue', 'command' => 'php artisan queue:work', 'numprocs' => 1, 'auto_start' => true, 'auto_restart' => true]
        : ['command' => 'php artisan schedule:run', 'frequency' => '* * * * *'];

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/{$resource}", [...$payload, 'site_id' => $site->id, 'user' => $user]);

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('user');
})->with(['workers', 'cron-jobs'])->with([
    'site user' => ['example_com', true],
    'server user' => ['artisan', false],
    'another site user' => ['other_com', false],
    'root' => ['root', false],
]);

it('keeps server-level jobs and unisolated site jobs on the server user', function (string $resource, bool $withSite, string $user, bool $allowed) {
    Queue::fake();

    isolatedLaravelSite($this->server);
    $plainSite = Site::factory()->forServer($this->server)->create();

    $payload = $resource === 'workers'
        ? ['name' => 'queue', 'command' => 'php artisan queue:work', 'numprocs' => 1, 'auto_start' => true, 'auto_restart' => true]
        : ['command' => 'php artisan schedule:run', 'frequency' => '* * * * *'];

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/{$resource}", [...$payload, 'site_id' => $withSite ? $plainSite->id : null, 'user' => $user]);

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('user');
})->with(['workers', 'cron-jobs'])->with([
    'server-level as server user' => [false, 'artisan', true],
    'server-level as a site user' => [false, 'example_com', false],
    'unisolated site as server user' => [true, 'artisan', true],
    'unisolated site as a site user' => [true, 'example_com', false],
]);

it('refuses to write supervisor and cron files for an isolated site job stored with the wrong user', function () {
    $site = isolatedLaravelSite($this->server);

    $worker = Worker::factory()->create(['server_id' => $this->server->id, 'site_id' => $site->id, 'user' => 'artisan']);
    $cronJob = CronJob::factory()->create(['server_id' => $this->server->id, 'site_id' => $site->id, 'user' => 'artisan']);

    expect(fn () => $worker->supervisorConfig('flitops-worker-1'))->toThrow(RuntimeException::class)
        ->and(fn () => $cronJob->cronLine())->toThrow(RuntimeException::class);

    $worker->update(['user' => 'example_com']);
    $cronJob->update(['user' => 'example_com']);

    expect($worker->fresh()->supervisorConfig('flitops-worker-1'))->toContain('user=example_com')
        ->and($cronJob->fresh()->cronLine())->toContain(' example_com cd /home/example_com/example.com/current && ');
});

it('defaults the site processes page to the site user', function () {
    $site = isolatedLaravelSite($this->server);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/processes")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('defaultUser', 'example_com'));
});

// --------------------------------------------------------------------------
// PHP version changes
// --------------------------------------------------------------------------

it('moves an isolated site pool when its php version changes', function () {
    Queue::fake();

    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.4', false);
    $site = isolatedLaravelSite($this->server);
    $plainSite = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $this->actingAs($this->user)->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['php_version' => '8.4']);
    $this->actingAs($this->user)->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$plainSite->id}", ['php_version' => '8.4']);

    Queue::assertPushed(MoveSitePhpPoolJob::class, fn ($job) => $job->site->is($site) && $job->previousPhpVersion === '8.3');
    Queue::assertPushed(SyncSiteNginxJob::class, fn ($job) => $job->site->is($plainSite));
    Queue::assertNotPushed(SyncSiteNginxJob::class, fn ($job) => $job->site->is($site));
});

it('moves isolated site pools when upgrading every site to the default php version', function () {
    Queue::fake();

    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.4', false);
    $site = isolatedLaravelSite($this->server);

    $this->actingAs($this->user)
        ->patch("/{$this->team->slug}/servers/{$this->server->id}/php/default-version", ['version' => '8.4', 'upgrade_sites' => true])
        ->assertRedirect();

    Queue::assertPushed(MoveSitePhpPoolJob::class, fn ($job) => $job->site->is($site) && $job->previousPhpVersion === '8.3');
});

it('creates the new pool and repoints nginx before removing the old pool', function () {
    $site = isolatedLaravelSite($this->server);
    $site->update(['php_version' => '8.4']);

    $commands = [];
    $uploads = [];
    bindIsolationSsh(isolationConnection($commands, $uploads));

    app()->call([new MoveSitePhpPoolJob($site, '8.3'), 'handle']);

    $newPool = array_search("sudo install -o root -g root -m 644 '/tmp/tmp.fleetops/pool.conf' '/etc/php/8.4/fpm/pool.d/example_com.conf'", $commands, true);
    $nginx = collect($commands)->search(fn ($c) => str_contains($c, 'fastcgi_pass unix:/run/php/php8.4-fpm-example_com.sock;'));
    $oldPool = array_search("sudo rm -f '/etc/php/8.3/fpm/pool.d/example_com.conf'", $commands, true);

    expect($newPool)->not->toBeFalse()
        ->and($nginx)->not->toBeFalse()
        ->and($oldPool)->not->toBeFalse()
        ->and($newPool)->toBeLessThan($nginx)
        ->and($nginx)->toBeLessThan($oldPool)
        ->and($commands)->toContain("sudo systemctl reload 'php8.3-fpm'")
        ->and($uploads['/tmp/tmp.fleetops/pool.conf'])->toContain('listen = /run/php/php8.4-fpm-example_com.sock');
});

// --------------------------------------------------------------------------
// Deleting a site
// --------------------------------------------------------------------------

it('removes the pool, then the site user and its home, when an isolated site is deleted', function () {
    Queue::fake();

    $site = isolatedLaravelSite($this->server);
    $site->update(['source_control_account_id' => null]);

    $commands = [];
    bindIsolationSsh(isolationConnection($commands, existingUserComment: SiteIsolationService::accountComment($site->ulid)));

    (new DeleteSiteJob($site))->handle(app(SshService::class), app(CleanupSiteExternalResourcesAction::class));

    $removePool = array_search("sudo rm -f '/etc/php/8.3/fpm/pool.d/example_com.conf'", $commands, true);
    $userdel = array_search("sudo userdel --remove 'example_com' 2>&1", $commands, true);

    expect($removePool)->not->toBeFalse()
        ->and($userdel)->not->toBeFalse()
        ->and($removePool)->toBeLessThan($userdel)
        ->and($commands)->toContain("sudo pkill -KILL -u 'example_com' || true")
        ->and(implode("\n", $commands))->not->toContain('artisan')
        ->and(implode("\n", $commands))->not->toContain('rm -rf /home/');

    $this->assertDatabaseMissing('sites', ['id' => $site->id]);
});

it('leaves a Linux user alone when it was not created for the deleted site', function () {
    Queue::fake();

    $site = isolatedLaravelSite($this->server);
    $site->update(['source_control_account_id' => null]);

    $commands = [];
    bindIsolationSsh(isolationConnection($commands, existingUserComment: 'Someone Else'));

    (new DeleteSiteJob($site))->handle(app(SshService::class), app(CleanupSiteExternalResourcesAction::class));

    expect(implode("\n", $commands))->not->toContain('userdel')
        ->and(implode("\n", $commands))->not->toContain('pkill');
});

it('never manages the server user or system accounts as a site user', function (string $name) {
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldNotReceive('exec');

    expect(fn () => app(SiteIsolationService::class)->deleteUser($connection, $name, '01HXYZ'))->toThrow(RuntimeException::class)
        ->and(fn () => app(SiteIsolationService::class)->removePool($connection, $name, '8.3'))->toThrow(RuntimeException::class);
})->with(['artisan', 'root', 'www-data', 'mysql', 'ubuntu', "x'; rm -rf /", '']);

it('deletes an unisolated site directory as before', function () {
    Queue::fake();

    $site = Site::factory()->forServer($this->server)->create([
        'domain' => 'example.com',
        'root_path' => 'example.com',
        'source_control_account_id' => null,
    ]);

    $commands = [];
    bindIsolationSsh(isolationConnection($commands));

    (new DeleteSiteJob($site))->handle(app(SshService::class), app(CleanupSiteExternalResourcesAction::class));

    expect($commands)->toContain('rm -rf /home/artisan/example.com')
        ->and(implode("\n", $commands))->not->toContain('userdel')
        ->and(implode("\n", $commands))->not->toContain('pool.d');
});
