<?php

namespace App\Services\Provisioning;

use App\Enums\NodeVersion;

class FinalTouchesService
{
    public function run(ProvisioningContext $context): void
    {
        // Composer (its installer runs on PHP)
        if ($context->server->hasPhp()) {
            $context->runner->runQuietly(
                'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer',
                300
            );
        }

        // Node.js
        $nodeVersion = $context->server->node_version ?? NodeVersion::Node24;
        $context->runner->runQuietly("curl -fsSL https://deb.nodesource.com/setup_{$nodeVersion->value}.x | bash -", 300);
        $context->packages->ensureInstalled('nodejs');

        // Supervisor
        $context->packages->ensureInstalled('supervisor');
        $context->services->enable('supervisor');
        $context->services->start('supervisor');

        // Git
        $context->packages->ensureInstalled('git');

        // Certbot (Let's Encrypt). Caddy gets its own certificates.
        if ($context->server->web_server !== 'caddy') {
            $context->packages->ensureInstalled('certbot');
        }

        // Firewall. The SSH port is the one FlitOps connects on, which a custom server may have moved off 22.
        $context->packages->ensureInstalled('ufw');
        $context->runner->runQuietly("ufw allow {$context->server->ssh_port}", 60);
        $context->runner->runQuietly('ufw allow 80', 60);
        $context->runner->runQuietly('ufw allow 443', 60);

        // Caddy serves HTTP/3, which runs over UDP.
        if ($context->server->web_server === 'caddy') {
            $context->runner->runQuietly('ufw allow 443/udp', 60);
        }
        $context->runner->runQuietly('ufw --force enable', 60);

        // Directories
        $serverUser = $context->serverUser;
        $context->runner->runQuietly("mkdir -p /home/{$serverUser}/sites && chown -R {$serverUser}:{$serverUser} /home/{$serverUser}", 120);

        // Cleanup
        $context->runner->runQuietly('DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=60 autoremove -y', 900);
        $context->runner->runQuietly('apt-get clean', 300);
    }
}
