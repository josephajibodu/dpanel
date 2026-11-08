<?php

namespace App\Data;

use App\Enums\ServerType;

readonly class ServerData
{
    public function __construct(
        public string $name,
        public int $providerAccountId,
        public string $region,
        public string $size,
        public ServerType $type = ServerType::App,
        public ?string $phpVersion = '8.5',
        public ?string $webServer = 'nginx',
        public ?string $databaseType = 'mysql',
        public bool $redis = true,
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
            type: ServerType::from($data['type']),
            phpVersion: $data['php_version'],
            webServer: $data['web_server'],
            databaseType: $data['database_type'],
            redis: (bool) $data['redis'],
        );
    }
}
