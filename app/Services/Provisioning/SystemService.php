<?php

namespace App\Services\Provisioning;

class SystemService
{
    public function prepareServer(ProvisioningContext $context): void
    {
        $serverUser = $context->serverUser;

        // Wait for cloud-init to complete if present.
        $context->runner->runQuietly('cloud-init status --wait 2>/dev/null || true', 300);

        // Create server user and configure sudoers.
        $context->runner->runQuietly(
            "if ! id \"{$serverUser}\" &>/dev/null; then ".
            "useradd -m -s /bin/bash {$serverUser} && ".
            "echo \"{$serverUser}:{$context->sudoPassword}\" | chpasswd && ".
            "usermod -aG sudo {$serverUser} && ".
            "echo \"{$serverUser} ALL=(ALL) NOPASSWD:ALL\" >> /etc/sudoers.d/{$serverUser} && ".
            "chmod 440 /etc/sudoers.d/{$serverUser}; ".
            'fi',
            120
        );

        // SSH directory and authorized_keys.
        $context->runner->runQuietly(
            "mkdir -p /home/{$serverUser}/.ssh && ".
            'if [ -f /root/.ssh/authorized_keys ]; then '.
            "cp /root/.ssh/authorized_keys /home/{$serverUser}/.ssh/; ".
            'fi && '.
            "chown -R {$serverUser}:{$serverUser} /home/{$serverUser}/.ssh && ".
            "chmod 700 /home/{$serverUser}/.ssh && ".
            "chmod 600 /home/{$serverUser}/.ssh/authorized_keys 2>/dev/null || true",
            120
        );

        // Generate local SSH key.
        $context->runner->runQuietly(
            "if [ ! -f /home/{$serverUser}/.ssh/id_ed25519 ]; then ".
            "ssh-keygen -t ed25519 -f /home/{$serverUser}/.ssh/id_ed25519 -N \"\" -C \"{$serverUser}@$(hostname)\" && ".
            "chown {$serverUser}:{$serverUser} /home/{$serverUser}/.ssh/id_ed25519* && ".
            "chmod 600 /home/{$serverUser}/.ssh/id_ed25519 && ".
            "chmod 644 /home/{$serverUser}/.ssh/id_ed25519.pub; ".
            'fi',
            120
        );
    }

    public function configureSwap(ProvisioningContext $context): void
    {
        $context->runner->runQuietly(
            'if [ ! -f /swapfile ]; then '.
            'fallocate -l 1G /swapfile && '.
            'chmod 600 /swapfile && '.
            'mkswap /swapfile && '.
            'swapon /swapfile && '.
            "echo '/swapfile none swap sw 0 0' >> /etc/fstab && ".
            "echo 'vm.swappiness=10' >> /etc/sysctl.conf && ".
            'sysctl vm.swappiness=10; '.
            'fi',
            300
        );
    }

    public function installBaseDependencies(ProvisioningContext $context): void
    {
        $context->packages->setup();

        // Fix any interrupted package operations.
        $context->runner->runQuietly('dpkg --configure -a || true', 600);
    }
}
