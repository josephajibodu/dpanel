<?php

namespace App\Actions\ServerPhp;

use App\Models\Server;
use App\Services\Ssh\SshService;
use RuntimeException;

class SetDefaultPhpVersion
{
    public function __construct(
        private SshService $sshService
    ) {}

    public function execute(Server $server, string $version): void
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $phpService = $server->service('php', $version);
        if ($phpService === null) {
            throw new RuntimeException("PHP {$version} service not found on this server.");
        }

        $server->installedServices()
            ->where('type', 'php')
            ->update(['is_default' => false]);
        $phpService->update(['is_default' => true]);

        $server->update(['php_version' => $version]);

        $connection = $this->sshService->connect($server);

        try {
            $connection->sudo("update-alternatives --set php /usr/bin/php{$version} 2>/dev/null || true", 10);
        } finally {
            $connection->disconnect();
        }
    }
}
