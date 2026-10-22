<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Nginx\SiteNginxSyncService;
use App\Services\SiteProvisioning\SiteIsolationService;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Moves an isolated site to a new PHP version: its own PHP-FPM pool is
 * created for the new version first, nginx is pointed at it, and only then
 * is the old version's pool removed, so the site keeps serving throughout.
 */
class MoveSitePhpPoolJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public Site $site,
        public ?string $previousPhpVersion,
    ) {}

    public function handle(SshService $sshService, SiteIsolationService $isolation, SiteNginxSyncService $nginx): void
    {
        $site = $this->site;
        $newVersion = $site->php_version ?? '8.4';

        $connection = $sshService->connect($site->server);

        try {
            $isolation->writePool($connection, $site, $newVersion);
            $nginx->sync($site, $connection);

            if ($this->previousPhpVersion !== null && $this->previousPhpVersion !== $newVersion) {
                $isolation->removePool($connection, $site->system_user, $this->previousPhpVersion);
            }
        } finally {
            $connection->disconnect();
        }
    }
}
