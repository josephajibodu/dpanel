<?php

namespace App\Actions\ServerPhp;

use App\Models\Server;
use App\Services\Ssh\SshService;
use RuntimeException;

class InstallPhpVersion
{
    private const EXTENSION_PACKAGES = [
        'mysql',
        'pgsql',
        'sqlite3',
        'redis',
        'curl',
        'gd',
        'mbstring',
        'xml',
        'zip',
        'bcmath',
        'intl',
        'readline',
    ];

    public function __construct(
        private SshService $sshService
    ) {}

    public function execute(Server $server, string $version): void
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $connection = $this->sshService->connect($server);

        try {
            $connection->sudo('DEBIAN_FRONTEND=noninteractive apt-get install -y software-properties-common', 60);
            $connection->sudo('add-apt-repository -y ppa:ondrej/php', 300);
            $connection->sudo('apt-get update', 120);

            $packages = [
                "php{$version}-fpm",
                "php{$version}-cli",
                "php{$version}-common",
            ];

            foreach (self::EXTENSION_PACKAGES as $ext) {
                $packages[] = "php{$version}-{$ext}";
            }

            $packagesList = implode(' ', $packages);
            $connection->sudo("DEBIAN_FRONTEND=noninteractive apt-get install -y {$packagesList}", 600);

            $connection->sudo("systemctl enable php{$version}-fpm", 10);
            $connection->sudo("systemctl start php{$version}-fpm", 30);
        } finally {
            $connection->disconnect();
        }
    }
}
