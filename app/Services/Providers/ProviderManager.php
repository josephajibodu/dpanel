<?php

namespace App\Services\Providers;

use App\Contracts\ProviderContract;
use App\Enums\Provider;
use App\Models\ProviderAccount;
use InvalidArgumentException;

class ProviderManager
{
    /** @var array<string, ProviderContract> */
    private array $drivers = [];

    /**
     * Get a provider driver by provider enum or string.
     */
    public function driver(Provider|string $provider): ProviderContract
    {
        $providerValue = $provider instanceof Provider ? $provider->value : $provider;

        return $this->drivers[$providerValue] ??= $this->createDriver($providerValue);
    }

    /**
     * Get a configured provider for a specific account.
     */
    public function forAccount(ProviderAccount $account): ProviderContract
    {
        $driver = $this->createDriver($account->provider->value);
        $driver->setCredentials($account->credentials);

        return $driver;
    }

    /**
     * Create a new provider driver instance.
     */
    private function createDriver(string $provider): ProviderContract
    {
        return match ($provider) {
            'digitalocean' => app(DigitalOceanProvider::class),
            'hetzner' => app(HetznerProvider::class),
            'vultr' => app(VultrProvider::class),
            default => throw new InvalidArgumentException("Unknown provider: {$provider}"),
        };
    }
}
