<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioning\PackageManager;
use App\Contracts\Remote\RemoteCommandRunner;

class AptPackageManager implements PackageManager
{
    public function __construct(
        private RemoteCommandRunner $runner,
    ) {}

    public function isInstalled(string $package): bool
    {
        try {
            $this->runner->run("dpkg -s {$package} >/dev/null 2>&1");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensureInstalled(string $package): void
    {
        if ($this->isInstalled($package)) {
            return;
        }

        $this->installOrFail($package);
    }

    public function installOrFail(string $package): void
    {
        $this->runner->runQuietly("apt-get -o DPkg::Lock::Timeout=60 install -y {$package}", 900);
    }

    public function setup(): void
    {
        $this->runner->runQuietly('export DEBIAN_FRONTEND=noninteractive', 5);
        $this->runner->runQuietly('apt-get -o DPkg::Lock::Timeout=60 update', 900);
    }

    public function isAvailable(): bool
    {
        try {
            $output = $this->runner->run('which apt-get || command -v apt-get', 10);

            return trim($output) !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
