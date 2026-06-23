<?php

namespace App\Data;

readonly class CustomServerData
{
    public function __construct(
        public string $name,
        public string $ipAddress,
        public int $sshPort = 22,
        public string $phpVersion = '8.3',
        public string $databaseType = 'mysql',
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
            phpVersion: $data['php_version'] ?? '8.3',
            databaseType: $data['database_type'] ?? 'mysql',
        );
    }
}
