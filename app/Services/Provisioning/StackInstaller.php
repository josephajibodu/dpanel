<?php

namespace App\Services\Provisioning;

use App\Contracts\Remote\RemoteCommandRunner;
use App\Enums\ConnectionStatus;
use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Server;
use App\Services\Remote\SshRemoteCommandRunner;
use App\Services\Remote\SshRemoteFilesystem;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for installing the full stack on a server over SSH.
 * Builds the provisioning context and runs system + service steps in order.
 */
class StackInstaller
{
    public function __construct(
        private SystemService $systemService,
        private PhpService $phpService,
        private NginxService $nginxService,
        private DatabaseService $databaseService,
        private RedisService $redisService,
        private FinalTouchesService $finalTouchesService,
    ) {}

    /**
     * Run the full stack installation for the server using the given SSH connection.
     * Caller is responsible for connecting and disconnecting.
     */
    public function install(Server $server, SshConnection $connection): void
    {
        $runner = new SshRemoteCommandRunner($connection);
        $files = new SshRemoteFilesystem($connection);
        $packages = new AptPackageManager($runner);
        $services = new SystemdServiceManager($runner);

        $databasePassword = $server->credentials()
            ->where('type', 'database_password')
            ->first()?->value ?? '';

        $sudoPassword = $server->credentials()
            ->where('type', 'sudo_password')
            ->first()?->value ?? '';

        $installationRunner = new ServiceInstallationRunner(
            phpService: $this->phpService,
            nginxService: $this->nginxService,
            databaseService: $this->databaseService,
            redisService: $this->redisService,
        );

        $context = new ProvisioningContext(
            server: $server,
            runner: $runner,
            files: $files,
            packages: $packages,
            services: $services,
            serverUser: config('server.user'),
            sudoPassword: $sudoPassword,
            databasePassword: $databasePassword,
            installationRunner: $installationRunner,
        );

        $server->update(['provisioning_step' => ProvisioningStep::PreparingServer]);
        $this->systemService->prepareServer($context);

        $server->update(['provisioning_step' => ProvisioningStep::ConfiguringSwap]);
        $this->systemService->configureSwap($context);

        $server->update(['provisioning_step' => ProvisioningStep::InstallingBaseDependencies]);
        $this->systemService->installBaseDependencies($context);

        $defaults = $server->type?->defaultServices() ?? [];

        if ($defaults['php'] ?? false) {
            $phpService = $server->createService(ServiceType::Php, $server->php_version, true);
            $context->service = $phpService;
            $phpService->install($context);
            $server->update(['php_version' => $phpService->installed_version ?? $phpService->version]);
        }

        if ($defaults['nginx'] ?? false) {
            $service = $server->createService(ServiceType::Nginx, null, true);
            $context->service = $service;
            $service->install($context);
        }

        if ($defaults['database'] ?? false) {
            $service = $server->createService($server->databaseServiceType(), null, true);
            $context->service = $service;
            $service->install($context);
        }

        if ($defaults['redis'] ?? false) {
            $service = $server->createService(ServiceType::Redis, null, true);
            $context->service = $service;
            $service->install($context);
        }

        $server->update(['provisioning_step' => ProvisioningStep::MakingFinalTouches]);
        $this->finalTouchesService->run($context);

        if ($defaults['supervisor'] ?? false) {
            $supervisor = $server->createService(ServiceType::Supervisor, null, true);
            $supervisor->update([
                'unit' => 'supervisor',
                'status' => ServiceStatus::Active,
            ]);
        }

        $updateData = [
            'status' => ServerStatus::Active,
            'provisioning_step' => ProvisioningStep::Finished,
            'connection_status' => ConnectionStatus::Successful,
            'provisioned_at' => now(),
        ];

        $this->appendMetadata($runner, $updateData);
        $server->update($updateData);
    }

    /**
     * @param  array<string, mixed>  $updateData
     */
    private function appendMetadata(RemoteCommandRunner $runner, array &$updateData): void
    {
        try {
            $ubuntuVersion = trim($runner->run('lsb_release -rs 2>/dev/null || cat /etc/os-release | grep VERSION_ID | cut -d"\" -f2', 30));
            if ($ubuntuVersion !== '') {
                $updateData['ubuntu_version'] = $ubuntuVersion;
            }
        } catch (\Throwable) {
            Log::error('Failed to get Ubuntu version', ['updateData' => $updateData]);
        }

        try {
            $localPublicKey = trim($runner->run('cat /home/'.config('server.user').'/.ssh/id_ed25519.pub 2>/dev/null || echo ""', 15));
            if ($localPublicKey !== '') {
                $updateData['local_public_key'] = $localPublicKey;
            }
        } catch (\Throwable) {
            Log::error('Failed to get local public key', ['updateData' => $updateData]);
        }
    }
}
