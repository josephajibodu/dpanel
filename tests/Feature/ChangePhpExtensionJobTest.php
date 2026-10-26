<?php

use App\Actions\ServerPhp\ChangePhpExtension;
use App\Enums\PhpExtension;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Jobs\ChangePhpExtensionJob;
use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fake the server's SSH connection. `$dpkgOutput` is what dpkg-query reports
 * about the PHP packages after the change; `$failOn` makes the sudo command
 * containing that text fail.
 *
 * @param  array<int, string>  $commands
 */
function fakePhpPackageServer(string $dpkgOutput, array &$commands = [], ?string $failOn = null): void
{
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('sudo')->andReturnUsing(function (string $command) use (&$commands, $failOn) {
        $commands[] = "sudo {$command}";

        if ($failOn !== null && str_contains($command, $failOn)) {
            throw new RuntimeException('E: Unable to locate package');
        }

        return '';
    });
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $dpkgOutput) {
        $commands[] = $command;

        return $dpkgOutput;
    });
    $connection->shouldReceive('disconnect');

    $ssh = Mockery::mock(SshService::class);
    $ssh->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $ssh);
}

beforeEach(function () {
    $this->server = Server::factory()->active()->create();
    $this->phpService = $this->server->createService(ServiceType::Php, '8.3', true);
    $this->phpService->update([
        'status' => ServiceStatus::Active,
        'installed_version' => '8.3',
        'type_data' => [
            'settings' => ['memory_limit' => '256M'],
            'extensions' => ['soap'],
            'extensions_sync_status' => 'pending',
            'extensions_pending' => ['extension' => 'imagick', 'action' => 'install'],
        ],
    ]);
});

it('installs the package, reloads PHP-FPM and records what is installed', function () {
    $commands = [];
    fakePhpPackageServer(implode("\n", [
        'php8.3-fpm installed',
        'php8.3-imagick installed',
        'php8.3-soap installed',
        'php8.3-gmp config-files',
    ]), $commands);

    (new ChangePhpExtensionJob($this->phpService, PhpExtension::Imagick, true))->handle(app(ChangePhpExtension::class));

    expect($commands)
        ->toContain('sudo DEBIAN_FRONTEND=noninteractive apt-get install -y php8.3-imagick')
        ->toContain('sudo systemctl reload php8.3-fpm');
    expect($this->phpService->fresh()->type_data)->toBe([
        'settings' => ['memory_limit' => '256M'],
        'extensions' => ['imagick', 'soap'],
        'extensions_sync_status' => 'synced',
        'extensions_pending' => null,
        'extensions_sync_error' => null,
    ]);
});

it('removes the package when asked to', function () {
    $commands = [];
    fakePhpPackageServer("php8.3-fpm installed\n", $commands);

    (new ChangePhpExtensionJob($this->phpService, PhpExtension::Soap, false))->handle(app(ChangePhpExtension::class));

    expect($commands)->toContain('sudo DEBIAN_FRONTEND=noninteractive apt-get remove -y php8.3-soap');
    expect($this->phpService->fresh()->type_data['extensions'])->toBe([]);
});

it('marks the change failed and keeps the recorded extensions when apt fails', function () {
    fakePhpPackageServer('', failOn: 'apt-get install');

    expect(fn () => (new ChangePhpExtensionJob($this->phpService, PhpExtension::Imagick, true))->handle(app(ChangePhpExtension::class)))
        ->toThrow(RuntimeException::class);

    expect($this->phpService->fresh()->type_data)->toMatchArray([
        'extensions' => ['soap'],
        'extensions_sync_status' => 'failed',
        'extensions_sync_error' => 'E: Unable to locate package',
        'extensions_pending' => null,
    ]);
});
