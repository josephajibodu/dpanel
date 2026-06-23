<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Nginx\NginxConfigService;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteSiteDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    protected string $hostname;

    protected string $configFileName;

    protected string $snippetsBasePath;

    protected bool $hasSsl;

    protected int $siteId;

    protected int $domainId;

    protected int $serverId;

    public function __construct(SiteDomain $siteDomain, Site $site)
    {
        $site->loadMissing('server');
        $siteDomain->loadMissing('site');

        $this->hostname = $siteDomain->hostname;
        $this->configFileName = NginxConfigService::configFileName($site, $siteDomain);
        $this->snippetsBasePath = $siteDomain->nginxSnippetsBasePath();
        $this->hasSsl = $siteDomain->hasSsl();
        $this->siteId = $site->id;
        $this->domainId = $siteDomain->id;
        $this->serverId = $site->server_id;
    }

    public function handle(SshService $sshService): void
    {
        $domain = SiteDomain::find($this->domainId);
        $server = Server::find($this->serverId);

        // Cascade delete removes associated SiteNginxFile rows automatically.
        $domain?->delete();

        if (! $server) {
            Log::warning("Server {$this->serverId} not found; skipping nginx cleanup for domain {$this->hostname}");

            return;
        }

        try {
            $connection = $sshService->connect($server);

            $connection->exec("sudo rm -f /etc/nginx/sites-enabled/{$this->configFileName}");
            $connection->exec("sudo rm -f /etc/nginx/sites-available/{$this->configFileName}");
            $connection->exec('sudo rm -rf '.escapeshellarg($this->snippetsBasePath));

            if ($this->hasSsl) {
                $certDir = "/etc/nginx/ssl/domains/{$this->siteId}/{$this->domainId}";
                $connection->exec("sudo rm -rf {$certDir}");
            }

            $testResult = $connection->exec('sudo nginx -t 2>&1');

            if (str_contains($testResult, 'syntax is ok')) {
                $connection->exec('sudo systemctl reload nginx');
            } else {
                Log::error("Nginx config invalid after domain deletion ({$this->hostname}): {$testResult}");
            }

            $connection->disconnect();
        } catch (\Throwable $e) {
            Log::error("Failed nginx cleanup for domain {$this->hostname}: {$e->getMessage()}", ['exception' => $e]);
        }
    }
}
