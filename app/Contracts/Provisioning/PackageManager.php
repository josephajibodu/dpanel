<?php

namespace App\Contracts\Provisioning;

interface PackageManager
{
    /**
     * Determine if the given package is installed.
     */
    public function isInstalled(string $package): bool;

    /**
     * Ensure that the given package is installed.
     *
     * Implementations SHOULD be idempotent.
     */
    public function ensureInstalled(string $package): void;

    /**
     * Install the given package, throwing an exception on failure.
     */
    public function installOrFail(string $package): void;

    /**
     * Perform any one-time setup required for the package manager.
     */
    public function setup(): void;

    /**
     * Determine if the package manager is available on the system.
     */
    public function isAvailable(): bool;
}
