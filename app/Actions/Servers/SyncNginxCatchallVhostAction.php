<?php

namespace App\Actions\Servers;

use App\Models\Server;
use App\Services\Provisioning\NginxService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;

/**
 * Push the current catch-all vhost (app.conf) and the no-site page it serves
 * to an already-provisioned server, so servers provisioned before either
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
        $installed = $connection->exec('dpkg -s ssl-cert >/dev/null 2>&1 && echo INSTALLED || true');
        if (! str_contains($installed, 'INSTALLED')) {
            $connection->exec('sudo apt-get -o DPkg::Lock::Timeout=60 install -y ssl-cert', 900);
        }

        $noSitePageRoot = NginxService::NO_SITE_PAGE_ROOT;
        $noSitePage = NginxService::NO_SITE_PAGE;
        $escapedPage = str_replace("'", "'\\''", NginxService::noSitePage());

        $connection->exec("sudo mkdir -p {$noSitePageRoot}");
        $connection->exec("echo '{$escapedPage}' | sudo tee {$noSitePage} > /dev/null");

        $escapedConfig = str_replace("'", "'\\''", NginxService::buildServerConfig());

        $connection->exec("echo '{$escapedConfig}' | sudo tee /etc/nginx/sites-available/app.conf > /dev/null");
        $connection->exec('sudo ln -sf /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/app.conf');

        $testResult = $connection->exec('sudo nginx -t 2>&1');
        if (! str_contains($testResult, 'syntax is ok')) {
            throw new \RuntimeException("Nginx configuration test failed on server {$server->id}: {$testResult}");
        }

        $connection->exec('sudo systemctl reload nginx');
    }
}
