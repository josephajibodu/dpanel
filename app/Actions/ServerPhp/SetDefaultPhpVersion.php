<?php

namespace App\Actions\ServerPhp;

use App\Enums\ServiceType;
use App\Jobs\SetDefaultPhpVersionJob;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\Site;
use RuntimeException;

class SetDefaultPhpVersion
{
    public function execute(Server $server, string $version, bool $upgradeSites = false): Service
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

        SetDefaultPhpVersionJob::dispatch($phpService);

        if ($upgradeSites) {
            $server->sites()
                ->get()
                ->each(function (Site $site) use ($version) {
                    $site->update(['php_version' => $version]);
                    SyncSiteNginxJob::dispatch($site)->onQueue('provisioning');
                });
        }

        return $phpService;
    }
}
