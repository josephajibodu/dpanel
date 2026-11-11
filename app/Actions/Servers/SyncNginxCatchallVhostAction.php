<?php

namespace App\Actions\Servers;

use App\Models\Server;
use App\Services\Provisioning\CaddyService;
use App\Services\Provisioning\NginxService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;

/**
 * Push the current catch-all config (Nginx's app.conf, or Caddy's main
 * Caddyfile) and the no-site page it serves to an already-provisioned server, so servers provisioned before either
 * changed get them retroactively without a full re-provision.
 */
class SyncNginxCatchallVhostAction
{
    public function __construct(
        private SshService $sshService,
    ) {}

    public function execute(Server $server): void
    {
        $connection = $this->sshService->connect($server);

        try {
            $this->sync($server, $connection);
        } finally {
            $connection->disconnect();
        }
    }

    private function sync(Server $server, SshConnection $connection): void
    {
        $noSitePageRoot = NginxService::NO_SITE_PAGE_ROOT;
        $connection->exec("sudo mkdir -p {$noSitePageRoot}");

        if ($server->web_server === 'caddy') {
            $this->write($connection, NginxService::NO_SITE_PAGE, CaddyService::noSitePage());
            $this->write($connection, CaddyService::CADDYFILE, CaddyService::buildCaddyfile());
        } else {
            $installed = $connection->exec('dpkg -s ssl-cert >/dev/null 2>&1 && echo INSTALLED || true');
            if (! str_contains($installed, 'INSTALLED')) {
                $connection->exec('sudo apt-get -o DPkg::Lock::Timeout=60 install -y ssl-cert', 900);
            }

            $this->write($connection, NginxService::NO_SITE_PAGE, NginxService::noSitePage());
            $this->write($connection, '/etc/nginx/sites-available/app.conf', NginxService::buildServerConfig());
            $connection->exec('sudo ln -sf /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/app.conf');
        }

        $server->webServer()->validate($connection);
        $server->webServer()->reload($connection);
    }

    private function write(SshConnection $connection, string $path, string $content): void
    {
        $escaped = str_replace("'", "'\\''", $content);

        $connection->exec("echo '{$escaped}' | sudo tee {$path} > /dev/null");
    }
}
