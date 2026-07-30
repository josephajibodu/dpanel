<?php

namespace App\Services\Provisioning;

use App\Contracts\Remote\RemoteCommandRunner;
use App\Enums\ConnectionStatus;
use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Events\ProvisioningOutput;
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
        $runner = new SshRemoteCommandRunner(
            $connection,
            fn (string $line) => $this->logOutput($server, $line),
        );
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

        $this->assertSupportedOperatingSystem($server, $runner);

        $server->update(['provisioning_step' => ProvisioningStep::PreparingServer]);
        $this->logStep($server, ProvisioningStep::PreparingServer);
        $this->systemService->prepareServer($context);

        $server->update(['provisioning_step' => ProvisioningStep::ConfiguringSwap]);
        $this->logStep($server, ProvisioningStep::ConfiguringSwap);
        $this->systemService->configureSwap($context);

        $server->update(['provisioning_step' => ProvisioningStep::InstallingBaseDependencies]);
        $this->logStep($server, ProvisioningStep::InstallingBaseDependencies);
        $this->systemService->installBaseDependencies($context);

        $defaults = $server->type?->defaultServices() ?? [];

        if ($defaults['php'] ?? false) {
            $this->logStep($server, ProvisioningStep::InstallingPhp);
            $phpService = $server->createService(ServiceType::Php, $server->php_version, true);
            $context->service = $phpService;
            $phpService->install($context);
            $server->update(['php_version' => $phpService->installed_version ?? $phpService->version]);
        }

        if ($defaults['nginx'] ?? false) {
            $this->logStep($server, ProvisioningStep::InstallingNginx);
            $service = $server->createService(ServiceType::Nginx, null, true);
            $context->service = $service;
            $service->install($context);
        }

        if ($defaults['database'] ?? false) {
            $this->logStep($server, ProvisioningStep::InstallingDatabase);
            $service = $server->createService($server->databaseServiceType(), null, true);
            $context->service = $service;
            $service->install($context);
        }

        if ($defaults['redis'] ?? false) {
            $this->logStep($server, ProvisioningStep::InstallingRedis);
            $service = $server->createService(ServiceType::Redis, null, true);
            $context->service = $service;
            $service->install($context);
        }

        $server->update(['provisioning_step' => ProvisioningStep::MakingFinalTouches]);
        $this->logStep($server, ProvisioningStep::MakingFinalTouches);
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
        $this->logOutput($server, ProvisioningStep::Finished->label(), 'success');
    }

    /**
     * Persist a provisioning transcript line and broadcast it live.
     */
    private function logOutput(Server $server, string $line, string $type = 'output'): void
    {
        $server->provisioningLogs()->create([
            'type' => $type,
            'message' => $line,
            'created_at' => now(),
        ]);

        broadcast(new ProvisioningOutput(server: $server, line: $line, type: $type));
    }

    /**
     * Log a narrated phase header (e.g. "Installing PHP") in the transcript.
     */
    private function logStep(Server $server, ProvisioningStep $step): void
    {
        $this->logOutput($server, $step->label(), 'info');
    }

    /**
     * Fail fast with a clear message if the server isn't running a supported
     * Ubuntu version, instead of discovering it partway through an opaque
     * apt-get error. Detection is best-effort: if the probe itself fails or
     * the OS can't be identified, provisioning continues rather than
     * blocking on a false positive.
     */
    private function assertSupportedOperatingSystem(Server $server, RemoteCommandRunner $runner): void
    {
        $this->logOutput($server, 'Checking operating system compatibility...', 'info');

        try {
            $osRelease = $runner->run('cat /etc/os-release 2>/dev/null || true', 15);
        } catch (\Throwable) {
            return;
        }

        $id = $this->extractOsReleaseValue($osRelease, 'ID');

        if ($id === '') {
            return;
        }

        $version = $this->extractOsReleaseValue($osRelease, 'VERSION_ID');
        $supported = config('server.supported_ubuntu_versions', []);

        if ($id === 'ubuntu' && in_array($version, $supported, true)) {
            return;
        }

        $supportedList = implode(', ', $supported);
        $message = "Unsupported server: detected {$id} {$version}. FlitOps currently supports Ubuntu ({$supportedList}) only.";

        $this->logOutput($server, $message, 'error');

        throw new \RuntimeException($message);
    }

    private function extractOsReleaseValue(string $osRelease, string $key): string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $osRelease, $matches)) {
            return '';
        }

        return trim($matches[1], "\"'\r\n ");
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
