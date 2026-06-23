<?php

namespace App\Services\Provisioning;

class FinalTouchesService
{
    public function run(ProvisioningContext $context): void
    {
        // Composer
        $context->runner->runQuietly(
            'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer',
            300
        );

        // Node.js 20
        $context->runner->runQuietly('curl -fsSL https://deb.nodesource.com/setup_20.x | bash -', 300);
        $context->packages->ensureInstalled('nodejs');

        // Supervisor
        $context->packages->ensureInstalled('supervisor');
        $context->services->enable('supervisor');
        $context->services->start('supervisor');

        // Git
        $context->packages->ensureInstalled('git');

        // Certbot (Let's Encrypt)
        $context->packages->ensureInstalled('certbot');

        // Firewall
        $context->packages->ensureInstalled('ufw');
        $context->runner->runQuietly('ufw allow 22', 60);
        $context->runner->runQuietly('ufw allow 80', 60);
        $context->runner->runQuietly('ufw allow 443', 60);
        $context->runner->runQuietly('ufw --force enable', 60);

        // Directories
        $serverUser = $context->serverUser;
        $context->runner->runQuietly("mkdir -p /home/{$serverUser}/sites && chown -R {$serverUser}:{$serverUser} /home/{$serverUser}", 120);

        // Cleanup
        $context->runner->runQuietly('apt-get -o DPkg::Lock::Timeout=60 autoremove -y', 900);
        $context->runner->runQuietly('apt-get clean', 300);
    }
}
