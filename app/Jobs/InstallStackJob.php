<?php

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Provisioning\AptPackageManager;
use App\Services\Provisioning\DatabaseService;
use App\Services\Provisioning\FinalTouchesService;
use App\Services\Provisioning\NginxService;
use App\Services\Provisioning\PhpService;
use App\Services\Provisioning\ProvisioningContext;
use App\Services\Provisioning\RedisService;
use App\Services\Provisioning\SystemdServiceManager;
use App\Services\Provisioning\SystemService;
use App\Services\Remote\SshRemoteCommandRunner;
use App\Services\Remote\SshRemoteFilesystem;
use App\Services\Ssh\SshRetryHandler;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class InstallStackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Server $server
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        SshService $sshService,
        SshRetryHandler $retryHandler,
        SystemService $systemService,
        PhpService $phpService,
        NginxService $nginxService,
        DatabaseService $databaseService,
        RedisService $redisService,
        FinalTouchesService $finalTouchesService,
    ): void {
        Log::info("Starting stack installation for server {$this->server->id}");

        // Set initial provisioning step
        $this->updateStep(ProvisioningStep::WaitingForServer);

        try {
            // Wait for SSH to become available (as root during provisioning)
            if (! $retryHandler->waitForSsh($this->server, 'root')) {
                throw new \Exception('SSH connection not available after maximum retries');
            }

            // Connect to server as root for provisioning
            $connection = $sshService->connectAsRoot($this->server);

            try {
                $runner = new SshRemoteCommandRunner($connection);
                $files = new SshRemoteFilesystem($connection);

                $packages = new AptPackageManager($runner);
                $services = new SystemdServiceManager($runner);

                $databasePassword = $this->server->credentials()
                    ->where('type', 'database_password')
                    ->first()?->value ?? '';

                $sudoPassword = $this->server->credentials()
                    ->where('type', 'sudo_password')
                    ->first()?->value ?? '';

                $context = new ProvisioningContext(
                    server: $this->server,
                    runner: $runner,
                    files: $files,
                    packages: $packages,
                    services: $services,
                    serverUser: config('server.user'),
                    sudoPassword: $sudoPassword,
                    databasePassword: $databasePassword,
                );

                // Preparing server
                $this->updateStep(ProvisioningStep::PreparingServer);
                $systemService->prepareServer($context);

                // Configuring swap
                $this->updateStep(ProvisioningStep::ConfiguringSwap);
                $systemService->configureSwap($context);

                // Base dependencies
                $this->updateStep(ProvisioningStep::InstallingBaseDependencies);
                $systemService->installBaseDependencies($context);

                // PHP
                $this->updateStep(ProvisioningStep::InstallingPhp);
                $phpService->install($context, $this->server->php_version);
                $phpService->configureFpmPool($context);

                // Nginx
                $this->updateStep(ProvisioningStep::InstallingNginx);
                $nginxService->install($context);

                // Database
                $this->updateStep(ProvisioningStep::InstallingDatabase);
                $databaseService->installForServer($context);

                // Redis
                $this->updateStep(ProvisioningStep::InstallingRedis);
                $redisService->install($context);

                // Final touches
                $this->updateStep(ProvisioningStep::MakingFinalTouches);
                $finalTouchesService->run($context);

                // Mark server as active with finished step
                $updateData = [
                    'status' => ServerStatus::Active,
                    'provisioning_step' => ProvisioningStep::Finished,
                    'connection_status' => ConnectionStatus::Successful,
                    'provisioned_at' => now(),
                ];

                // Collect simple metadata.
                try {
                    $ubuntuVersion = trim($runner->run('lsb_release -rs 2>/dev/null || cat /etc/os-release | grep VERSION_ID | cut -d\"\\\"\" -f2', 30));
                    if ($ubuntuVersion !== '') {
                        $updateData['ubuntu_version'] = $ubuntuVersion;
                    }
                } catch (\Throwable) {
                    // Ignore metadata errors.
                }

                try {
                    $localPublicKey = trim($runner->run('cat /home/'.config('server.user').'/.ssh/id_ed25519.pub 2>/dev/null || echo ""', 15));
                    if ($localPublicKey !== '') {
                        $updateData['local_public_key'] = $localPublicKey;
                    }
                } catch (\Throwable) {
                    // Ignore metadata errors.
                }

                $this->server->update($updateData);

                Log::info("Server {$this->server->id} provisioned successfully");

            } finally {
                $connection->disconnect();
            }

        } catch (\Throwable $e) {
            Log::error(
                "Failed to install stack on server {$this->server->id}",
                [
                    'server_id' => $this->server->id,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'previous' => $e->getPrevious() ? [
                        'exception' => get_class($e->getPrevious()),
                        'message' => $e->getPrevious()->getMessage(),
                    ] : null,
                ]
            );

            $this->server->update(['status' => ServerStatus::Error]);

            throw $e;
        }
    }

    /**
     * Update the provisioning step for the server.
     */
    private function updateStep(ProvisioningStep $step): void
    {
        $this->server->update(['provisioning_step' => $step]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error(
            "InstallStackJob failed for server {$this->server->id}",
            [
                'server_id' => $this->server->id,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'previous' => $exception->getPrevious() ? [
                    'exception' => get_class($exception->getPrevious()),
                    'message' => $exception->getPrevious()->getMessage(),
                    'trace' => $exception->getPrevious()->getTraceAsString(),
                ] : null,
            ]
        );

        $this->server->update(['status' => ServerStatus::Error]);
    }
}
