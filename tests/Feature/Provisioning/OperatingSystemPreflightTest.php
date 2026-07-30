<?php

use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Provisioning\DatabaseService;
use App\Services\Provisioning\FinalTouchesService;
use App\Services\Provisioning\NginxService;
use App\Services\Provisioning\PhpService;
use App\Services\Provisioning\RedisService;
use App\Services\Provisioning\StackInstaller;
use App\Services\Provisioning\SystemService;
use App\Services\Ssh\SshConnection;

/**
 * Mocks SshConnection so that the os-release probe returns $osReleaseContent
 * verbatim and every other command succeeds with no output.
 */
function connectionReportingOsRelease(string $osReleaseContent): SshConnection
{
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldIgnoreMissing();

    $connection->shouldReceive('execWithOutput')
        ->andReturnUsing(function (string $command, callable $onOutput) use ($osReleaseContent) {
            if (str_contains($command, '/etc/os-release')) {
                foreach (explode("\n", trim($osReleaseContent)) as $line) {
                    $onOutput($line);
                }
            }

            return 0;
        });

    return $connection;
}

function mockProvisioningServices(): void
{
    foreach ([SystemService::class, PhpService::class, NginxService::class, DatabaseService::class, RedisService::class, FinalTouchesService::class] as $service) {
        test()->mock($service)->shouldIgnoreMissing();
    }
}

it('rejects an unsupported operating system before doing any real work', function () {
    mockProvisioningServices();

    $server = Server::factory()->create([
        'status' => ServerStatus::Provisioning,
        'provisioning_step' => ProvisioningStep::WaitingForServer,
    ]);

    $connection = connectionReportingOsRelease(<<<'OS_RELEASE'
        PRETTY_NAME="Debian GNU/Linux 12 (bookworm)"
        NAME="Debian GNU/Linux"
        VERSION_ID="12"
        ID=debian
        OS_RELEASE);

    expect(fn () => app(StackInstaller::class)->install($server, $connection))
        ->toThrow(RuntimeException::class, 'Unsupported server: detected debian 12. FlitOps currently supports Ubuntu (22.04, 24.04, 26.04) only.');

    $server->refresh();

    // Provisioning never got past the OS check.
    expect($server->provisioning_step)->toBe(ProvisioningStep::WaitingForServer)
        ->and($server->provisioningLogs()->where('type', 'error')->pluck('message')->all())
        ->toContain('Unsupported server: detected debian 12. FlitOps currently supports Ubuntu (22.04, 24.04, 26.04) only.');
});

it('proceeds when the server reports a supported Ubuntu version', function () {
    mockProvisioningServices();

    $server = Server::factory()->create([
        'status' => ServerStatus::Provisioning,
        'provisioning_step' => ProvisioningStep::WaitingForServer,
    ]);

    $connection = connectionReportingOsRelease(<<<'OS_RELEASE'
        PRETTY_NAME="Ubuntu 24.04.1 LTS"
        NAME="Ubuntu"
        VERSION_ID="24.04"
        ID=ubuntu
        OS_RELEASE);

    app(StackInstaller::class)->install($server, $connection);

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Active)
        ->and($server->provisioning_step)->toBe(ProvisioningStep::Finished);
});

it('proceeds when the operating system cannot be determined, rather than blocking on a false positive', function () {
    mockProvisioningServices();

    $server = Server::factory()->create([
        'status' => ServerStatus::Provisioning,
        'provisioning_step' => ProvisioningStep::WaitingForServer,
    ]);

    // No /etc/os-release content at all (e.g. an unusual minimal image).
    $connection = connectionReportingOsRelease('');

    app(StackInstaller::class)->install($server, $connection);

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Active)
        ->and($server->provisioning_step)->toBe(ProvisioningStep::Finished);
});
