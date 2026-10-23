<?php

use App\Actions\Certificates\SyncWildcardCertificateForDomainAction;
use App\Actions\CronJob\CreateCronJob;
use App\Actions\CronJob\EnableCronJob;
use App\Actions\CronJob\UpdateCronJob;
use App\Actions\ServerPhp\UpdatePhpSettings;
use App\Actions\Worker\CreateWorker;
use App\Actions\Worker\UpdateWorker;
use App\Enums\ConnectionStatus;
use App\Enums\ServerStatus;
use App\Exceptions\SshCommandException;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Worker;
use App\Services\Certificates\WildcardCertificateIssuer;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

/*
 * Files that root installs into system locations must be staged in a private
 * mktemp -d directory (faked as /tmp/tmp.fleetops), never at a /tmp path that
 * a site's user could guess, pre-create or swap.
 */

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::Active,
        'connection_status' => ConnectionStatus::Successful,
        'php_version' => '8.3',
    ]);
});

/**
 * Point SshService at a connection that records uploads and commands.
 *
 * @param  array<string, string>  $uploads
 * @param  list<string>  $commands
 */
function bindStagingConnection(array &$uploads, array &$commands, ?Closure $onExec = null): void
{
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn(mockDatabaseConnection($uploads, $commands, $onExec));
    app()->instance(SshService::class, $sshService);
}

/*
 * Each case runs one action and returns the system path it should install,
 * alongside the mode that file should get.
 */
dataset('files root installs', [
    'new cron job' => [function (Server $server): string {
        $cronJob = CronJob::factory()->create(['server_id' => $server->id, 'hidden' => false]);
        app(CreateCronJob::class)->execute($cronJob);

        return "/etc/cron.d/flitops-cron-{$cronJob->id}";
    }, '644'],
    'updated cron job' => [function (Server $server): string {
        $cronJob = CronJob::factory()->create(['server_id' => $server->id, 'hidden' => false]);
        app(UpdateCronJob::class)->execute($cronJob);

        return "/etc/cron.d/flitops-cron-{$cronJob->id}";
    }, '644'],
    're-enabled cron job' => [function (Server $server): string {
        $cronJob = CronJob::factory()->create(['server_id' => $server->id, 'hidden' => false]);
        app(EnableCronJob::class)->execute($cronJob);

        return "/etc/cron.d/flitops-cron-{$cronJob->id}";
    }, '644'],
    'new worker' => [function (Server $server): string {
        $worker = Worker::factory()->create(['server_id' => $server->id]);
        app(CreateWorker::class)->execute($worker);

        return "/etc/supervisor/conf.d/flitops-worker-{$worker->id}.conf";
    }, '644'],
    'updated worker' => [function (Server $server): string {
        $worker = Worker::factory()->create(['server_id' => $server->id]);
        app(UpdateWorker::class)->execute($worker);

        return "/etc/supervisor/conf.d/flitops-worker-{$worker->id}.conf";
    }, '644'],
    'php.ini settings' => [function (Server $server): string {
        app(UpdatePhpSettings::class)->execute($server, ['memory_limit' => '256M']);

        return '/etc/php/8.3/fpm/php.ini';
    }, '644'],
]);

it('stages the file in a private directory and installs it root-owned', function (Closure $run, string $mode) {
    $uploads = [];
    $commands = [];
    bindStagingConnection($uploads, $commands);

    $destination = $run($this->server);

    expect(array_keys($uploads))->toBe(['/tmp/tmp.fleetops/staged'])
        ->and($commands)->toContain('mktemp -d')
        ->and($commands)->toContain("sudo install -o root -g root -m '{$mode}' '/tmp/tmp.fleetops/staged' '{$destination}'")
        ->and($commands)->toContain("rm -rf '/tmp/tmp.fleetops'");

    foreach ($commands as $command) {
        expect(preg_replace('#/tmp/tmp\.fleetops\b#', '', $command))->not->toContain('/tmp/')
            ->and($command)->not->toStartWith('sudo mv ');
    }
})->with('files root installs');

it('removes the private directory when installing the file fails', function (Closure $run) {
    $uploads = [];
    $commands = [];
    bindStagingConnection($uploads, $commands, function (string $command): void {
        if (str_starts_with($command, 'sudo install ')) {
            throw new SshCommandException($command, 1, '', 'install: permission denied');
        }
    });

    expect(fn () => $run($this->server))->toThrow(SshCommandException::class);

    expect($commands)->toContain("rm -rf '/tmp/tmp.fleetops'");
})->with('files root installs');

it('stages the wildcard certificate and key privately and removes the directory even when install fails', function () {
    Config::set('server.free_domain', 'flitops.test');

    $certDir = sys_get_temp_dir().'/private-staging-cert-'.bin2hex(random_bytes(4));
    @mkdir($certDir, 0700, true);
    file_put_contents("{$certDir}/server.crt", 'FAKE-CERT');
    file_put_contents("{$certDir}/server.key", 'FAKE-KEY');

    Certificate::factory()->create([
        'domain' => '*.flitops.test',
        'certificate_path' => "{$certDir}/server.crt",
        'private_key_path' => "{$certDir}/server.key",
    ]);

    try {
        $uploads = [];
        $commands = [];
        $connection = mockDatabaseConnection($uploads, $commands, function (string $command): void {
            if (str_starts_with($command, 'sudo install ') && str_contains($command, 'server.key')) {
                throw new SshCommandException($command, 1, '', 'install: permission denied');
            }
        });

        expect(fn () => (new SyncWildcardCertificateForDomainAction(new WildcardCertificateIssuer))->execute($connection, siteId: 4, domainId: 2))
            ->toThrow(SshCommandException::class);

        expect(array_keys($uploads))->toBe(['/tmp/tmp.fleetops/staged'])
            ->and($commands)->toContain("sudo install -o root -g root -m '0644' '/tmp/tmp.fleetops/staged' '/etc/nginx/ssl/domains/4/2/server.crt'")
            ->and($commands)->toContain("sudo install -o root -g root -m '0600' '/tmp/tmp.fleetops/staged' '/etc/nginx/ssl/domains/4/2/server.key'")
            ->and(array_count_values($commands)["rm -rf '/tmp/tmp.fleetops'"])->toBe(2)
            ->and(implode("\n", $commands))->not->toContain('/tmp/wildcard_');
    } finally {
        @unlink("{$certDir}/server.crt");
        @unlink("{$certDir}/server.key");
        @rmdir($certDir);
    }
});

it('takes the PHP-FPM reload lock as root in /run, not in the shared /tmp', function (Closure $makeSite) {
    $site = $makeSite($this->server);
    $strategy = app(DeploymentStrategy::class);

    $reload = $strategy->reloadPhpFpm($site);

    expect($reload)->toContain('sudo -S flock -w 10 /run/flitops-php-fpm.lock service "$PHP_FPM" reload')
        ->and($reload)->not->toContain('/tmp/')
        ->and($strategy->append($site))->not->toContain('/tmp/fpmlock');
})->with([
    'site run by the server user' => [fn (Server $server): Site => Site::factory()->forServer($server)->create()],
    'isolated site' => [fn (Server $server): Site => Site::factory()->forServer($server)->isolated()->create()],
]);
