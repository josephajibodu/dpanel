<?php

namespace App\Contracts\Provisioning;

interface ServiceManager
{
    /**
     * Start the given service or services.
     *
     * @param  string|array<int, string>  $services
     */
    public function start(string|array $services): void;

    /**
     * Stop the given service or services.
     *
     * @param  string|array<int, string>  $services
     */
    public function stop(string|array $services): void;

    /**
     * Restart the given service or services.
     *
     * @param  string|array<int, string>  $services
     */
    public function restart(string|array $services): void;

    /**
     * Enable the given service or services so they start on boot.
     *
     * @param  string|array<int, string>  $services
     */
    public function enable(string|array $services): void;

    /**
     * Disable the given service or services so they do not start on boot.
     *
     * @param  string|array<int, string>  $services
     */
    public function disable(string|array $services): void;

    /**
     * Reload the given service or services (reload configuration without full restart).
     *
     * @param  string|array<int, string>  $services
     */
    public function reload(string|array $services): void;

    /**
     * Determine if the service manager is available on the system.
     */
    public function isAvailable(): bool;
}
