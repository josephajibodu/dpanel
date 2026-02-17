<?php

use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Jobs\InstallStackJob;
use App\Models\Server;
use App\Services\Provisioning\DatabaseService;
use App\Services\Provisioning\FinalTouchesService;
use App\Services\Provisioning\NginxService;
use App\Services\Provisioning\PhpService;
use App\Services\Provisioning\RedisService;
use App\Services\Provisioning\StackInstaller;
use App\Services\Provisioning\SystemService;
use App\Services\Ssh\SshRetryHandler;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

it('orchestrates provisioning steps in order', function () {
    Queue::fake();

    /** @var Server $server */
    $server = Server::factory()->create([
        'status' => ServerStatus::Provisioning,
        'provisioning_step' => ProvisioningStep::WaitingForServer,
    ]);

    // Fake credentials needed by the job.
    $server->credentials()->createMany([
        [
            'type' => 'private_key',
            'value' => 'test-private-key',
        ],
        [
            'type' => 'database_password',
            'value' => 'secret-password',
        ],
        [
            'type' => 'sudo_password',
            'value' => 'sudo-secret',
        ],
    ]);

    // Mock SSH and retry behaviour – we only care that the services are called.
    $this->mock(SshRetryHandler::class, function (MockInterface $mock) use ($server) {
        $mock->shouldReceive('waitForSsh')
            ->once()
            ->withArgs(function (Server $argServer, string $username) use ($server) {
                return $argServer->is($server) && $username === 'root';
            })
            ->andReturn(true);
    });

    $this->mock(SshService::class, function (MockInterface $mock) use ($server) {
        $connection = Mockery::mock(\App\Services\Ssh\SshConnection::class);
        $connection->shouldIgnoreMissing();

        $mock->shouldReceive('connectAsRoot')
            ->once()
            ->with($server)
            ->andReturn($connection);
    });

    $services = [
        SystemService::class,
        PhpService::class,
        NginxService::class,
        DatabaseService::class,
        RedisService::class,
        FinalTouchesService::class,
    ];

    foreach ($services as $service) {
        $this->mock($service)->shouldIgnoreMissing();
    }

    $job = new InstallStackJob($server);

    $job->handle(
        app(SshService::class),
        app(SshRetryHandler::class),
        app(StackInstaller::class),
    );

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Active)
        ->and($server->provisioning_step)->toBe(ProvisioningStep::Finished);
});
