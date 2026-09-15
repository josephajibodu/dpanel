<?php

namespace App\Services\Storage;

use App\Contracts\StorageProviderContract;
use App\Enums\StorageProviderType;
use App\Models\StorageProvider;
use InvalidArgumentException;

class StorageProviderManager
{
    /** @var array<string, StorageProviderContract> */
    private array $drivers = [];

    /**
     * Get a storage driver by type enum or string.
     */
    public function driver(StorageProviderType|string $type): StorageProviderContract
    {
        $typeValue = $type instanceof StorageProviderType ? $type->value : $type;

        return $this->drivers[$typeValue] ??= $this->createDriver($typeValue);
    }

    /**
     * Get a configured driver for a specific connected account.
     */
    public function forAccount(StorageProvider $storageProvider): StorageProviderContract
    {
        $driver = $this->createDriver($storageProvider->type->value);
        $driver->setCredentials($storageProvider->credentials);

        return $driver;
    }

    private function createDriver(string $type): StorageProviderContract
    {
        return match ($type) {
            'cloudflare_r2' => app(CloudflareR2Driver::class),
            's3' => app(S3Driver::class),
            default => throw new InvalidArgumentException("Unknown storage provider type: {$type}"),
        };
    }
}
