<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioning\ServiceManager;
use App\Contracts\Remote\RemoteCommandRunner;

class SystemdServiceManager implements ServiceManager
{
    public function __construct(
        private RemoteCommandRunner $runner,
    ) {}

    public function start(string|array $services): void
    {
        foreach ($this->normalizeServices($services) as $service) {
            $this->runner->runQuietly("systemctl start {$service}");
        }
    }

    public function stop(string|array $services): void
    {
        foreach ($this->normalizeServices($services) as $service) {
            $this->runner->runQuietly("systemctl stop {$service}");
        }
    }

    public function restart(string|array $services): void
    {
        foreach ($this->normalizeServices($services) as $service) {
            $this->runner->runQuietly("systemctl restart {$service}");
        }
    }

    public function enable(string|array $services): void
    {
        foreach ($this->normalizeServices($services) as $service) {
            $this->runner->runQuietly("systemctl enable {$service}");
        }
    }

    public function disable(string|array $services): void
    {
        foreach ($this->normalizeServices($services) as $service) {
            $this->runner->runQuietly("systemctl disable {$service}");
        }
    }

    public function reload(string|array $services): void
    {
        foreach ($this->normalizeServices($services) as $service) {
            $this->runner->runQuietly("systemctl reload {$service}");
        }
    }

    public function isAvailable(): bool
    {
        try {
            $output = $this->runner->run('which systemctl || command -v systemctl', 10);

            return trim($output) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeServices(string|array $services): array
    {
        return is_array($services) ? $services : [$services];
    }
}
