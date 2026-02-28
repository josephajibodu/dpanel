<?php

namespace App\Services\Provisioning;

class PhpService
{
    public function install(ProvisioningContext $context, string $phpVersion): void
    {
        // Base tools needed for the PPA.
        $context->packages->ensureInstalled('software-properties-common');

        $context->runner->runQuietly('add-apt-repository -y ppa:ondrej/php', 300);
        $context->packages->setup();

        $packages = [
            "php{$phpVersion}-fpm",
            "php{$phpVersion}-cli",
            "php{$phpVersion}-common",
            "php{$phpVersion}-mysql",
            "php{$phpVersion}-pgsql",
            "php{$phpVersion}-sqlite3",
            "php{$phpVersion}-redis",
            "php{$phpVersion}-curl",
            "php{$phpVersion}-gd",
            "php{$phpVersion}-mbstring",
            "php{$phpVersion}-xml",
            "php{$phpVersion}-zip",
            "php{$phpVersion}-bcmath",
            "php{$phpVersion}-intl",
            "php{$phpVersion}-readline",
        ];

        foreach ($packages as $package) {
            $context->packages->ensureInstalled($package);
        }

        $serviceName = "php{$phpVersion}-fpm";

        $context->services->enable($serviceName);
        $context->services->start($serviceName);
    }

    public function configureFpmPool(ProvisioningContext $context, ?string $phpVersion = null): void
    {
        $phpVersion = $phpVersion ?? $context->server->php_version;
        $serverUser = $context->serverUser;

        $poolConfig = <<<CONF
[{$serverUser}]
user = {$serverUser}
group = {$serverUser}
listen = /run/php/php{$phpVersion}-fpm-{$serverUser}.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
CONF;

        $path = "/etc/php/{$phpVersion}/fpm/pool.d/{$serverUser}.conf";

        $context->files->put($path, $poolConfig);

        $context->services->restart("php{$phpVersion}-fpm");
    }
}
