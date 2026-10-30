<?php

namespace App\Data;

use App\Enums\ServerType;

readonly class CustomServerData
{
    public function __construct(
        public string $name,
        public string $ipAddress,
        public int $sshPort = 22,
        public ServerType $type = ServerType::App,
        public ?string $phpVersion = '8.3',
        public ?string $webServer = 'nginx',
        public ?string $databaseType = 'mysql',
        public bool $redis = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        return new self(
            name: $data['name'],
            ipAddress: $data['ip_address'],
            sshPort: (int) ($data['ssh_port'] ?? 22),
            type: ServerType::from($data['type']),
            phpVersion: $data['php_version'],
            webServer: $data['web_server'],
            databaseType: $data['database_type'],
            redis: (bool) $data['redis'],
        );
    }
}
