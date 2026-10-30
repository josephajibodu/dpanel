<?php

namespace App\Jobs;

use App\Enums\SiteDomainType;
use App\Events\SiteDomainsUpdated;
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

    protected bool $isCustom;

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
        $this->isCustom = $siteDomain->type === SiteDomainType::Custom;
        $this->siteId = $site->id;
        $this->domainId = $siteDomain->id;
        $this->serverId = $site->server_id;
    }

    public function handle(SshService $sshService): void
    {
        $domain = SiteDomain::find($this->domainId);
        $site = Site::find($this->siteId);
        $server = Server::find($this->serverId);

        $domain?->delete();

        if ($site) {
            broadcast(new SiteDomainsUpdated($site));
        }

        if (! $server) {
            Log::warning("Server {$this->serverId} not found; skipping nginx cleanup for domain {$this->hostname}");

            return;
        }

        try {
            $connection = $sshService->connect($server);
            $webServer = $server->webServer();

            foreach (array_reverse($webServer->siteConfigPaths($this->configFileName)) as $configPath) {
                $connection->exec("sudo rm -f {$configPath}");
            }
            $connection->exec('sudo rm -rf '.escapeshellarg($this->snippetsBasePath));

            if ($this->hasSsl) {
                $certDir = $webServer->certificateDirectory($this->siteId, $this->domainId);
                $connection->exec("sudo rm -rf {$certDir}");
            }

            $connection->exec('sudo rm -f '.IssueSslForDomainJob::deployHookPath($this->domainId));

            if ($this->isCustom) {
                $connection->exec('sudo certbot delete --non-interactive --cert-name '.escapeshellarg($this->hostname).' 2>&1 || true');
            }

            try {
                $webServer->validateAndReload($connection);
            } catch (\RuntimeException $exception) {
                Log::error("Web server config invalid after domain deletion ({$this->hostname}): {$exception->getMessage()}");
            }

            $connection->disconnect();
        } catch (\Throwable $e) {
            Log::error("Failed nginx cleanup for domain {$this->hostname}: {$e->getMessage()}", ['exception' => $e]);
        }
    }
}
