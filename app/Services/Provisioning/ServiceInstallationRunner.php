<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceType;

/**
 * Runs the actual installer for a given service type and returns the systemd unit and version.
 */
class ServiceInstallationRunner
{
    public function __construct(
        private PhpService $phpService,
        private NginxService $nginxService,
        private DatabaseService $databaseService,
        private RedisService $redisService,
    ) {}

    /**
     * Install the given service type using the context. Returns unit name and installed version.
     *
     * @return array{unit: string, installed_version: string|null}
     */
    public function run(ServiceType $type, ProvisioningContext $context): array
    {
        return match ($type) {
            ServiceType::Php => $this->installPhp($context),
            ServiceType::Nginx => $this->installNginx($context),
            ServiceType::Mysql, ServiceType::Postgresql => $this->installDatabase($context),
            ServiceType::Redis => $this->installRedis($context),
            default => throw new \InvalidArgumentException("Unknown service type for installation: {$type->value}"),
        };
    }

    /**
     * @return array{unit: string, installed_version: string|null}
     */
    private function installPhp(ProvisioningContext $context): array
    {
        $version = $context->service?->version ?? $context->server->php_version;
        $this->phpService->install($context, $version);
        $this->phpService->configureFpmPool($context, $version);

        return [
            'unit' => "php{$version}-fpm",
            'installed_version' => $version,
        ];
    }

    /**
     * @return array{unit: string, installed_version: string|null}
     */
    private function installNginx(ProvisioningContext $context): array
    {
        $this->nginxService->install($context);

        return [
            'unit' => 'nginx',
            'installed_version' => null,
        ];
    }

    /**
     * @return array{unit: string, installed_version: string|null}
     */
    private function installDatabase(ProvisioningContext $context): array
    {
        $this->databaseService->installForServer($context);
        $unit = match ($context->server->database_type) {
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'postgresql' => 'postgresql',
            default => 'mysql',
        };

        return [
            'unit' => $unit,
            'installed_version' => null,
        ];
    }

    /**
     * @return array{unit: string, installed_version: string|null}
     */
    private function installRedis(ProvisioningContext $context): array
    {
        $this->redisService->install($context);

        return [
            'unit' => 'redis-server',
            'installed_version' => null,
        ];
    }
}
