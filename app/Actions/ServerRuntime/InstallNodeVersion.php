<?php

namespace App\Actions\ServerRuntime;

use App\Enums\NodeVersion;
use App\Models\Server;
use App\Services\Ssh\SshService;
use RuntimeException;

class InstallNodeVersion
{
    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * Switch the server's system-wide Node.js to the given major version.
     *
     * The NodeSource setup script points apt at the new major's repository.
     * The newest package from that repository is then installed by exact
     * version, so moving to an older major works as well as a newer one.
     */
    public function execute(Server $server, NodeVersion $version): void
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $connection = $this->sshService->connect($server);

        try {
            $connection->sudo("bash -c 'curl -fsSL https://deb.nodesource.com/setup_{$version->value}.x | bash -'", 300);

            $package = trim($connection->exec("apt-cache madison nodejs | grep nodesource | head -n 1 | awk '{print \$3}'"));

            if (! preg_match('/^'.$version->value.'\.[0-9A-Za-z.+~-]+$/', $package)) {
                throw new RuntimeException("Could not find a Node.js {$version->value} package to install.");
            }

            $connection->sudo("DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades nodejs={$package}", 600);

            $installed = trim($connection->exec('node --version'));

            if (! str_starts_with($installed, "v{$version->value}.")) {
                throw new RuntimeException("Expected Node.js {$version->value} but the server reports {$installed}.");
            }
        } finally {
            $connection->disconnect();
        }
    }
}
