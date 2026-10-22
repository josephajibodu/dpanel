<?php

use App\Actions\Sites\IsolateSiteAction;
use App\Enums\ConnectionStatus;
use App\Enums\DeploymentStatus;
use App\Enums\NginxFileSection;
use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Enums\SiteDomainType;
use App\Enums\SiteStatus;
use App\Enums\TeamRoleKey;
use App\Jobs\IsolateSiteJob;
use App\Jobs\IssueSslForDomainJob;
use App\Models\CronJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use App\Services\SiteProvisioning\SiteIsolationService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const OLD_ROOT = '/home/artisan/example.com';
const NEW_ROOT = '/home/example_com/example.com';

/**
 * A fake connection for a conversion. Records every command and upload in
 * order; `$where` is what the "where are the files" check answers, and
 * `$failWhen` makes a matching command throw.
 *
 * @param  array<int, string>  $commands
 * @param  array<int, array{path: string, content: string}>  $uploads
 */
function conversionConnection(array &$commands, array &$uploads, string $where = 'old', string $existingUserComment = '', ?Closure $failWhen = null): SshConnection
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $where, &$existingUserComment, $failWhen) {
        $commands[] = $command;

        if (preg_match("/^sudo useradd .*--comment '([^']+)'/", $command, $match)) {
            $existingUserComment = $match[1];
        }

        if ($failWhen && $failWhen($command)) {
            throw new RuntimeException('simulated failure');
        }

        return match (true) {
            $command === 'mktemp -d' => '/tmp/tmp.fleetops',
            str_contains($command, 'nginx -t') => 'syntax is ok',
            str_starts_with($command, 'getent passwd') => $existingUserComment,
            str_contains($command, 'echo old; elif') => $where,
            default => '',
        };
    });
    $connection->shouldReceive('upload')->andReturnUsing(function (string $content, string $path) use (&$uploads) {
        $uploads[] = ['path' => $path, 'content' => $content];
    });
    $connection->shouldReceive('disconnect')->andReturnNull();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    return $connection;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
        'connection_status' => ConnectionStatus::Successful,
    ]);

    $this->site = Site::factory()->forServer($this->server)->create([
        'domain' => 'example.com',
        'root_path' => 'example.com',
        'project_type' => ProjectType::Laravel,
        'php_version' => '8.3',
        'status' => SiteStatus::Deployed,
        'source_control_account_id' => null,
    ]);
    $this->site->deployScript()->create(['script' => 'cp '.OLD_ROOT."/shared/extra.txt .\n\$PHP artisan migrate --force"]);

    $domain = $this->site->domains()->where('is_primary', true)->first();
    $domain->update(['type' => SiteDomainType::Custom, 'hostname' => 'example.com']);

    $this->mainNginx = SiteNginxFile::factory()->forSite($this->site)->create([
        'site_domain_id' => $domain->id,
        'section' => NginxFileSection::Main,
        'path' => 'example-com.conf',
        'content' => "server {\n    root ".OLD_ROOT."/current/public;\n    # my edit\n    fastcgi_pass unix:/run/php/php8.3-fpm-artisan.sock;\n}\n",
    ]);

    $this->worker = Worker::factory()->create([
        'server_id' => $this->server->id, 'site_id' => $this->site->id, 'user' => 'artisan', 'status' => 'active',
    ]);
    $this->stoppedWorker = Worker::factory()->create([
        'server_id' => $this->server->id, 'site_id' => $this->site->id, 'user' => 'artisan', 'status' => 'stopped',
    ]);
    $this->cronJob = CronJob::factory()->create([
        'server_id' => $this->server->id, 'site_id' => $this->site->id, 'user' => 'artisan', 'status' => 'active',
        'command' => 'php artisan schedule:run', 'frequency' => '* * * * *', 'hidden' => false,
    ]);
    $this->serverWorker = Worker::factory()->create([
        'server_id' => $this->server->id, 'site_id' => null, 'user' => 'artisan', 'status' => 'active',
    ]);
});

function convertSite(Site $site): void
{
    app(IsolateSiteAction::class)->execute($site->fresh(), SiteStatus::Deployed);
}

// --------------------------------------------------------------------------
// Happy path
// --------------------------------------------------------------------------

it('moves a site to its own user and rewrites everything that named the old folder', function () {
    $commands = [];
    $uploads = [];
    conversionConnection($commands, $uploads);

    convertSite($this->site);

    $site = $this->site->fresh();
    $all = implode("\n", $commands);

    expect($site->system_user)->toBe('example_com')
        ->and($site->status)->toBe(SiteStatus::Deployed)
        ->and($site->error_message)->toBeNull()
        ->and($site->rootPath())->toBe(NEW_ROOT);

    // User, pool, and the move itself (with a record of the original owners).
    expect($all)->toContain("sudo useradd --create-home --home-dir '/home/example_com'")
        ->and($all)->toContain("sudo install -o root -g root -m 644 '/tmp/tmp.fleetops/pool.conf' '/etc/php/8.3/fpm/pool.d/example_com.conf'")
        ->and($commands)->toContain("sudo find '".OLD_ROOT."' -printf '%U:%G %m %p\\0' > '/tmp/tmp.fleetops/ownership'")
        ->and($commands)->toContain("sudo mv -T '".OLD_ROOT."' '".NEW_ROOT."'")
        ->and($commands)->toContain("sudo chown -R 'example_com':'example_com' '".NEW_ROOT."'");

    // Symlinks (current, shared links) and the SQLite path in .env.
    expect(collect($commands)->contains(fn ($c) => str_starts_with($c, "sudo -u 'example_com'") && str_contains($c, '-type l -lname') && str_contains($c, OLD_ROOT.'/*')))->toBeTrue()
        ->and($all)->toContain('s#/home/artisan/example\\.com/#/home/example_com/example.com/#g')
        ->and($all)->toContain("chmod 600 '\\''".NEW_ROOT."/shared/.env'\\''")
        ->and($all)->toContain('artisan config:cache');

    // nginx: the stored config (user edit kept) points at the new folder and socket.
    expect($this->mainNginx->fresh()->content)->toContain('root '.NEW_ROOT.'/current/public;')
        ->toContain('# my edit')
        ->toContain('fastcgi_pass unix:/run/php/php8.3-fpm-example_com.sock;')
        ->not->toContain('artisan')
        ->and($all)->toContain('root '.NEW_ROOT.'/current/public;')
        ->and($all)->toContain('root /var/lib/flitops/acme;');

    // Let's Encrypt renewals move to the root-only challenge folder.
    expect($all)->toContain("sudo sed -i 's#= /home/artisan/example\\.com/current/public\\(,\\?\\)\$#= /var/lib/flitops/acme\\1#' '/etc/letsencrypt/renewal/example.com.conf'");

    // Workers and cron jobs run as the site user from the new folder.
    $workerFile = collect($uploads)->firstWhere('path', "/tmp/tmp.fleetops/flitops-worker-{$this->worker->id}.conf");
    $cronFile = collect($uploads)->firstWhere('path', "/tmp/tmp.fleetops/flitops-cron-{$this->cronJob->id}");

    expect($workerFile['content'])->toContain('user=example_com')->toContain('directory='.NEW_ROOT.'/current')
        ->and($cronFile['content'])->toContain('* * * * * example_com cd '.NEW_ROOT.'/current && php artisan schedule:run')
        ->and($all)->toContain("sudo install -o root -g root -m 644 '/tmp/tmp.fleetops/flitops-worker-{$this->worker->id}.conf' '/etc/supervisor/conf.d/flitops-worker-{$this->worker->id}.conf'")
        ->and($commands)->toContain('sudo supervisorctl update')
        ->and($commands)->toContain("sudo supervisorctl stop 'flitops-worker-{$this->stoppedWorker->id}:*'");

    expect($this->worker->fresh()->user)->toBe('example_com')
        ->and($this->stoppedWorker->fresh()->user)->toBe('example_com')
        ->and($this->cronJob->fresh()->user)->toBe('example_com')
        ->and($this->serverWorker->fresh()->user)->toBe('artisan')
        ->and($site->deployScript->script)->toContain('cp '.NEW_ROOT.'/shared/extra.txt');

    // Nothing is staged in a guessable /tmp path.
    expect(collect($uploads)->pluck('path')->every(fn ($path) => str_starts_with($path, '/tmp/tmp.fleetops/')))->toBeTrue();
});

it('switches nginx only after the files are in place', function () {
    $commands = [];
    $uploads = [];
    conversionConnection($commands, $uploads);

    convertSite($this->site);

    $move = array_search("sudo mv -T '".OLD_ROOT."' '".NEW_ROOT."'", $commands, true);
    $nginxReload = array_search('sudo systemctl reload nginx', $commands, true);

    expect($move)->toBeLessThan($nginxReload);
});

// --------------------------------------------------------------------------
// Rollback
// --------------------------------------------------------------------------

it('puts everything back exactly as it was when a late step fails', function () {
    $commands = [];
    $uploads = [];
    conversionConnection($commands, $uploads, failWhen: fn (string $c) => $c === 'sudo supervisorctl update');

    expect(fn () => convertSite($this->site))->toThrow(RuntimeException::class, 'simulated failure');

    $site = $this->site->fresh();

    expect($site->system_user)->toBeNull()
        ->and($site->status)->toBe(SiteStatus::Deployed)
        ->and($site->error_message)->toContain('put back as it was')
        ->and($site->rootPath())->toBe(OLD_ROOT)
        ->and($this->worker->fresh()->user)->toBe('artisan')
        ->and($this->cronJob->fresh()->user)->toBe('artisan')
        ->and($this->mainNginx->fresh()->content)->toContain('root '.OLD_ROOT.'/current/public;')
        ->and($this->mainNginx->fresh()->content)->toContain('php8.3-fpm-artisan.sock')
        ->and($site->deployScript->script)->toContain('cp '.OLD_ROOT.'/shared/extra.txt');

    $failure = array_search('sudo supervisorctl update', $commands, true);
    $after = array_slice($commands, $failure + 1);
    $afterAll = implode("\n", $after);

    // Process files rewritten for the server user again.
    $rewrittenWorker = collect($uploads)->where('path', "/tmp/tmp.fleetops/flitops-worker-{$this->worker->id}.conf")->last();
    expect($rewrittenWorker['content'])->toContain('user=artisan')->toContain('directory='.OLD_ROOT.'/current');

    // Each finished step undone, newest first.
    $find = fn (string $needle) => collect($after)->search(fn ($c) => str_contains($c, $needle));

    $renewal = $find("sudo sed -i 's#= /var/lib/flitops/acme");
    $nginx = $find('root '.OLD_ROOT.'/current/public;');
    $relink = collect($after)->search(fn ($c) => str_contains($c, '-type l -lname') && str_contains($c, NEW_ROOT.'/*'));
    $moveBack = $find("sudo mv -T '".NEW_ROOT."' '".OLD_ROOT."'");
    $restoreOwners = $find("done < \"\$1\"' _ '/tmp/tmp.fleetops/ownership'");
    $pool = $find("sudo rm -f '/etc/php/8.3/fpm/pool.d/example_com.conf'");
    $userdel = $find("sudo userdel --remove 'example_com'");

    expect([$renewal, $nginx, $relink, $moveBack, $restoreOwners, $pool, $userdel])->each->not->toBeFalse()
        ->and($renewal)->toBeLessThan($nginx)
        ->and($nginx)->toBeLessThan($relink)
        ->and($relink)->toBeLessThan($moveBack)
        ->and($moveBack)->toBeLessThan($restoreOwners)
        ->and($restoreOwners)->toBeLessThan($pool)
        ->and($pool)->toBeLessThan($userdel)
        ->and($afterAll)->toContain('s#/home/example_com/example\\.com/#/home/artisan/example.com/#g');
});

it('only undoes the steps that had started when an early step fails', function () {
    $commands = [];
    $uploads = [];
    conversionConnection($commands, $uploads, failWhen: fn (string $c) => str_contains($c, "sudo mv -T '".OLD_ROOT."'"));

    expect(fn () => convertSite($this->site))->toThrow(RuntimeException::class);

    $all = implode("\n", $commands);

    expect($this->site->fresh()->system_user)->toBeNull()
        ->and($all)->not->toContain('nginx -t')
        ->and($all)->not->toContain('supervisorctl')
        ->and($all)->toContain("sudo rm -f '/etc/php/8.3/fpm/pool.d/example_com.conf'")
        ->and($all)->toContain("sudo userdel --remove 'example_com'");
});

it('keeps the site user when the files could not be moved back', function () {
    $commands = [];
    $uploads = [];
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        if ($command === 'sudo supervisorctl update' || str_contains($command, "sudo mv -T '".NEW_ROOT."'")) {
            throw new RuntimeException('simulated failure');
        }

        return match (true) {
            $command === 'mktemp -d' => '/tmp/tmp.fleetops',
            str_contains($command, 'nginx -t') => 'syntax is ok',
            str_contains($command, 'echo old; elif') => 'old',
            str_contains($command, 'then echo yes; fi') => 'yes',
            default => '',
        };
    });
    $connection->shouldReceive('upload');
    $connection->shouldReceive('disconnect');
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    expect(fn () => convertSite($this->site))->toThrow(RuntimeException::class);

    expect(implode("\n", $commands))->not->toContain('userdel');
});

// --------------------------------------------------------------------------
// Retrying
// --------------------------------------------------------------------------

it('finishes a conversion an earlier attempt left half-done', function () {
    $commands = [];
    $uploads = [];
    conversionConnection($commands, $uploads, where: 'new', existingUserComment: SiteIsolationService::accountComment($this->site->ulid));

    convertSite($this->site);

    $all = implode("\n", $commands);

    expect($this->site->fresh()->system_user)->toBe('example_com')
        ->and($all)->not->toContain('useradd')
        ->and($all)->not->toContain("sudo mv -T '".OLD_ROOT."'")
        ->and($all)->toContain("sudo chown -R 'example_com':'example_com' '".NEW_ROOT."'");
});

it('stops without touching the server when it cannot tell where the files are', function () {
    $commands = [];
    $uploads = [];
    conversionConnection($commands, $uploads, where: 'unknown');

    expect(fn () => convertSite($this->site))->toThrow(RuntimeException::class, "Couldn't tell where the site's files are");

    expect(implode("\n", $commands))->not->toContain('mv -T')
        ->and($this->site->fresh()->system_user)->toBeNull();
});

it('does nothing for a site that is already isolated', function () {
    $this->site->update(['system_user' => 'example_com', 'status' => SiteStatus::Isolating]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');
    app()->instance(SshService::class, $sshService);

    convertSite($this->site);

    expect($this->site->fresh()->status)->toBe(SiteStatus::Deployed);
});

// --------------------------------------------------------------------------
// Starting a conversion
// --------------------------------------------------------------------------

it('queues the conversion from the site page and marks the site as isolating', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/isolate")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->site->fresh()->status)->toBe(SiteStatus::Isolating);
    Queue::assertPushed(IsolateSiteJob::class, fn ($job) => $job->site->is($this->site) && $job->previousStatus === SiteStatus::Deployed);
});

it('lets members who manage sites isolate one, and nobody else', function (TeamRoleKey $role, bool $allowed) {
    Queue::fake();

    $member = User::factory()->memberOf($this->team, $role)->create();

    $response = $this->actingAs($member)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/isolate");

    $allowed ? $response->assertRedirect() : $response->assertForbidden();
})->with([
    'admin' => [TeamRoleKey::Admin, true],
    'developer' => [TeamRoleKey::Developer, true],
    'viewer' => [TeamRoleKey::Viewer, false],
]);

it('refuses to start a conversion the site is not ready for', function (Closure $setUp, string $message) {
    Queue::fake();

    $setUp($this->site);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/isolate")
        ->assertSessionHasErrors(['isolate' => $message]);

    Queue::assertNotPushed(IsolateSiteJob::class);
})->with([
    'already isolated' => [fn (Site $site) => $site->update(['system_user' => 'example_com']), 'This site is already isolated.'],
    'still installing' => [fn (Site $site) => $site->update(['status' => SiteStatus::Installing]), 'Only a site that is set up and not busy can be isolated. Wait for the current task to finish and try again.'],
    'deploying' => [fn (Site $site) => Deployment::factory()->forSite($site)->create(['status' => DeploymentStatus::Running]), 'A deployment is running. Try again once it finishes.'],
]);

it('refuses to deploy while the site is being isolated', function () {
    Queue::fake();
    $this->site->update(['status' => SiteStatus::Isolating]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments")
        ->assertSessionHasErrors('deploy');

    expect($this->site->deployments()->count())->toBe(0);
});

it('puts the site back to its old status when the job is killed partway', function () {
    $this->site->update(['status' => SiteStatus::Isolating]);

    (new IsolateSiteJob($this->site, SiteStatus::Deployed))->failed(new RuntimeException('timed out'));

    expect($this->site->fresh()->status)->toBe(SiteStatus::Deployed)
        ->and($this->site->fresh()->error_message)->toContain('stopped before it finished');
});

// --------------------------------------------------------------------------
// The artisan command
// --------------------------------------------------------------------------

it('queues a conversion for every site on the server still on the server user', function () {
    Queue::fake();

    $second = Site::factory()->forServer($this->server)->create(['status' => SiteStatus::Provisioned]);
    $alreadyIsolated = Site::factory()->forServer($this->server)->isolated()->create(['status' => SiteStatus::Deployed]);
    $otherServerSite = Site::factory()->create(['status' => SiteStatus::Deployed]);

    $this->artisan('flitops:server:isolate-sites', ['server' => $this->server->id, '--force' => true])
        ->assertSuccessful();

    Queue::assertPushed(IsolateSiteJob::class, 2);
    Queue::assertPushed(IsolateSiteJob::class, fn ($job) => $job->site->is($this->site));
    Queue::assertPushed(IsolateSiteJob::class, fn ($job) => $job->site->is($second));
    Queue::assertNotPushed(IsolateSiteJob::class, fn ($job) => $job->site->is($alreadyIsolated) || $job->site->is($otherServerSite));
});

it('asks before converting and does nothing when told no', function () {
    Queue::fake();

    $this->artisan('flitops:server:isolate-sites', ['server' => $this->server->id])
        ->expectsConfirmation('Isolate these 1 sites?', 'no')
        ->assertFailed();

    Queue::assertNotPushed(IsolateSiteJob::class);
    expect($this->site->fresh()->status)->toBe(SiteStatus::Deployed);
});

it('reports sites it had to skip', function () {
    Queue::fake();
    $this->site->update(['status' => SiteStatus::Deploying]);

    $this->artisan('flitops:server:isolate-sites', ['server' => $this->server->id, '--force' => true])
        ->assertFailed();

    Queue::assertNotPushed(IsolateSiteJob::class);
});

// --------------------------------------------------------------------------
// Let's Encrypt challenges
// --------------------------------------------------------------------------

it('issues certificates for isolated sites through the root-only challenge folder', function () {
    $this->site->update(['system_user' => 'example_com']);
    $domain = $this->site->domains()->first();

    $commands = [];
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return str_contains($command, 'certbot certonly') ? 'Congratulations!' : '';
    });
    $connection->shouldReceive('disconnect');
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    Queue::fake();

    try {
        (new IssueSslForDomainJob($domain, $this->site->fresh()))->handle($sshService);
    } catch (Throwable) {
        // Reading the certificate back isn't faked; only the commands matter here.
    }

    $all = implode("\n", $commands);

    expect($all)->toContain("sudo mkdir -p '/var/lib/flitops/acme'/.well-known/acme-challenge")
        ->and($all)->toContain("--webroot-path='/var/lib/flitops/acme'")
        ->and($all)->not->toContain(NEW_ROOT)
        ->and($all)->not->toContain('sudo -u');
});

it('keeps issuing certificates for other sites through their web root', function () {
    $domain = $this->site->domains()->first();

    $commands = [];
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return str_contains($command, 'certbot certonly') ? 'Congratulations!' : '';
    });
    $connection->shouldReceive('disconnect');
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    Queue::fake();

    try {
        (new IssueSslForDomainJob($domain, $this->site->fresh()))->handle($sshService);
    } catch (Throwable) {
        // As above.
    }

    expect(implode("\n", $commands))->toContain("--webroot-path='".OLD_ROOT."/current/public'")
        ->not->toContain('/var/lib/flitops/acme');
});
