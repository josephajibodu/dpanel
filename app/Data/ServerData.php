<?php

namespace App\Data;

readonly class ServerData
{
    public function __construct(
        public string $name,
        public int $providerAccountId,
        public string $region,
        public string $size,
        public string $phpVersion = '8.3',
        public string $databaseType = 'mysql',
    ) {}

    /**
     * Create from validated request data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        return new self(
            name: $data['name'],
            providerAccountId: $data['provider_account_id'],
            region: $data['region'],
            size: $data['size'],
            phpVersion: $data['php_version'] ?? '8.3',
            databaseType: $data['database_type'] ?? 'mysql',
        );
    }
}
