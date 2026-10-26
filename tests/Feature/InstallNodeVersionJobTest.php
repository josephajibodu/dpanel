<?php

use App\Actions\ServerRuntime\InstallNodeVersion;
use App\Enums\NodeVersion;
use App\Enums\ServiceStatus;
use App\Jobs\InstallNodeVersionJob;
use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fake the server's SSH connection. `$madison` is what apt reports as the
 * newest NodeSource package and `$nodeVersion` what `node --version` prints.
 *
 * @param  array<int, string>  $commands
 */
function fakeNodeServer(string $madison, string $nodeVersion, array &$commands = []): void
{
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('sudo')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = "sudo {$command}";

        return '';
    });
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $madison, $nodeVersion) {
        $commands[] = $command;

        return str_starts_with($command, 'apt-cache madison') ? "{$madison}\n" : "{$nodeVersion}\n";
    });
    $connection->shouldReceive('disconnect');

    $ssh = Mockery::mock(SshService::class);
    $ssh->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $ssh);
}

beforeEach(function () {
    $this->server = Server::factory()->active()->create([
        'node_version' => NodeVersion::Node24,
        'node_status' => ServiceStatus::Pending,
    ]);
});

it('installs the exact NodeSource package for the chosen version and records it', function () {
    $commands = [];
    fakeNodeServer('22.21.0-1nodesource1', 'v22.21.0', $commands);

    (new InstallNodeVersionJob($this->server, NodeVersion::Node22))->handle(app(InstallNodeVersion::class));

    expect($commands)->toContain("sudo bash -c 'curl -fsSL https://deb.nodesource.com/setup_22.x | bash -'")
        ->toContain('sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades nodejs=22.21.0-1nodesource1');

    $this->server->refresh();
    expect($this->server->node_version)->toBe(NodeVersion::Node22)
        ->and($this->server->node_status)->toBe(ServiceStatus::Active);
});

it('marks the switch failed and keeps the old version when it does not complete', function (string $madison, string $nodeVersion) {
    fakeNodeServer($madison, $nodeVersion);

    expect(fn () => (new InstallNodeVersionJob($this->server, NodeVersion::Node22))->handle(app(InstallNodeVersion::class)))
        ->toThrow(RuntimeException::class);

    $this->server->refresh();
    expect($this->server->node_version)->toBe(NodeVersion::Node24)
        ->and($this->server->node_status)->toBe(ServiceStatus::Failed);
})->with([
    'no package for that version' => ['', 'v24.11.0'],
    'package from a different major' => ['24.11.0-1nodesource1', 'v24.11.0'],
    'node still reports the old version' => ['22.21.0-1nodesource1', 'v24.11.0'],
]);
