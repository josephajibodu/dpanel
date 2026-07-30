<?php

use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Jobs\InstallStackJob;
use App\Models\Server;
use App\Notifications\ServerProvisioningFailed;
use App\Services\Provisioning\DatabaseService;
use App\Services\Provisioning\FinalTouchesService;
use App\Services\Provisioning\NginxService;
use App\Services\Provisioning\PhpService;
use App\Services\Provisioning\RedisService;
use App\Services\Provisioning\StackInstaller;
use App\Services\Provisioning\SystemService;
use App\Services\Ssh\SshRetryHandler;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Notification;
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

it('stores the error message and sends a notification when the job fails', function () {
    Notification::fake();

    $server = Server::factory()->provisioning()->create([
        'provisioning_step' => ProvisioningStep::InstallingNginx,
    ]);

    $exception = new \RuntimeException('SSH connection timed out');

    $job = new InstallStackJob($server);
    $job->failed($exception);

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Error)
        ->and($server->error_message)->toBe('SSH connection timed out');

    Notification::assertSentTo(
        $server->user,
        ServerProvisioningFailed::class,
        fn (ServerProvisioningFailed $notification) => $notification->server->is($server)
            && $notification->errorMessage === 'SSH connection timed out'
    );
});

it('redacts sudo and database passwords from the stored and notified error message', function () {
    Notification::fake();

    $server = Server::factory()->provisioning()->create([
        'provisioning_step' => ProvisioningStep::InstallingNginx,
    ]);

    $server->credentials()->createMany([
        ['type' => 'sudo_password', 'value' => 'super-secret-sudo'],
        ['type' => 'database_password', 'value' => 'super-secret-db'],
    ]);

    $exception = new \RuntimeException(
        "SSH command failed with exit code 1: echo \"deploy:super-secret-sudo\" | chpasswd\n".
        "STDERR: ALTER USER 'root'@'localhost' IDENTIFIED BY 'super-secret-db';"
    );

    $job = new InstallStackJob($server);
    $job->failed($exception);

    $server->refresh();

    expect($server->error_message)
        ->not->toContain('super-secret-sudo')
        ->not->toContain('super-secret-db')
        ->toContain('[redacted]');

    Notification::assertSentTo(
        $server->user,
        ServerProvisioningFailed::class,
        fn (ServerProvisioningFailed $notification) => ! str_contains($notification->errorMessage, 'super-secret-sudo')
            && ! str_contains($notification->errorMessage, 'super-secret-db')
    );
});
