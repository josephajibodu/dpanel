<?php

namespace App\Actions\ServerPhp;

use App\Enums\ServiceType;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshService;
use RuntimeException;

class SetDefaultPhpVersion
{
    public function __construct(
        private SshService $sshService
    ) {}

    public function execute(Server $server, string $version, bool $upgradeSites = false): void
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $phpService = $server->service(ServiceType::Php, $version);
        if ($phpService === null) {
            throw new RuntimeException("PHP {$version} service not found on this server.");
        }

        $server->installedServices()
            ->where('type', ServiceType::Php)
            ->update(['is_default' => false]);
        $phpService->update(['is_default' => true]);

        $server->update(['php_version' => $version]);

        $connection = $this->sshService->connect($server);

        try {
            $connection->sudo("update-alternatives --set php /usr/bin/php{$version} 2>/dev/null || true", 10);
        } finally {
            $connection->disconnect();
        }

        if ($upgradeSites) {
            $server->sites()
                ->get()
                ->each(function (Site $site) use ($version) {
                    $site->update(['php_version' => $version]);
                    SyncSiteNginxJob::dispatch($site)->onQueue('provisioning');
                });
        }
    }
}
