<?php

use App\Contracts\Provisioning\PackageManager;
use App\Contracts\Provisioning\ServiceManager;
use App\Contracts\Remote\RemoteCommandRunner;
use App\Contracts\Remote\RemoteFilesystem;
use App\Models\Server;
use App\Services\Provisioning\FinalTouchesService;
use App\Services\Provisioning\ProvisioningContext;

/**
 * Run the final touches for the server and return every command it ran.
 *
 * @return array<int, string>
 */
function runFinalTouches(Server $server): array
{
    $commands = [];

    $runner = Mockery::mock(RemoteCommandRunner::class);
    $runner->shouldReceive('run', 'runQuietly')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return '';
    });

    $packages = Mockery::mock(PackageManager::class);
    $packages->shouldReceive('ensureInstalled');
    $services = Mockery::mock(ServiceManager::class);
    $services->shouldReceive('enable', 'start');

    app(FinalTouchesService::class)->run(new ProvisioningContext(
        server: $server,
        runner: $runner,
        files: Mockery::mock(RemoteFilesystem::class),
        packages: $packages,
        services: $services,
        serverUser: 'flitops',
        sudoPassword: '',
        databasePassword: '',
    ));

    return $commands;
}

it('installs composer on servers with PHP', function () {
    $commands = runFinalTouches(Server::factory()->make());

    expect(implode("\n", $commands))->toContain('getcomposer.org/installer');
});

it('skips composer on servers without PHP', function () {
    $commands = runFinalTouches(Server::factory()->cache()->make());

    expect(implode("\n", $commands))->not->toContain('getcomposer.org');
});
