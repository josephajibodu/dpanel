<?php

namespace App\Jobs;

use App\Actions\Sites\CleanupSiteExternalResourcesAction;
use App\Events\ServerSitesUpdated;
use App\Models\Site;
use App\Services\Nginx\NginxConfigService;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    protected string $domain;

    protected string $siteRoot;

    protected int $serverId;

    protected int $siteId;

    protected ?string $repository;

    protected ?int $sourceControlAccountId;

    /**
     * @var array<int, string>
     */
    protected array $cloudflareDnsRecordIds;

    /**
     * @var array<int, string>
     */
    protected array $nginxConfigBasenames;

    public function __construct(Site $site)
    {
        $site->loadMissing('domains');
        $this->domain = $site->domain;
        $this->siteRoot = $site->rootPath();
        $this->serverId = $site->server_id;
        $this->siteId = $site->id;
        $this->repository = $site->repository;
        $this->sourceControlAccountId = $site->source_control_account_id;
        $this->cloudflareDnsRecordIds = $site->domains
            ->pluck('cloudflare_dns_record_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->nginxConfigBasenames = $site->domains
            ->map(fn ($d) => NginxConfigService::configFileName($site, $d))
            ->values()
            ->all();
    }

    public function handle(
        SshService $sshService,
        CleanupSiteExternalResourcesAction $cleanupAction,
    ): void {
        Log::info("Deleting site {$this->domain}");

        $site = Site::find($this->siteId);
        $server = \App\Models\Server::find($this->serverId);

        $cleanupAction->execute(
            cloudflareDnsRecordIds: $this->cloudflareDnsRecordIds,
            server: $server,
            sourceControlAccount: $site?->sourceControlAccount,
            domain: $this->domain,
        );

        if (! $server) {
            Log::warning("Server {$this->serverId} not found, skipping site deletion");

            if ($site) {
                $site->delete();
            }

            return;
        }

        if ($site) {
            $site->delete();
        }

        try {
            $connection = $sshService->connect($server);

            foreach ($this->nginxConfigBasenames as $basename) {
                $configPath = "/etc/nginx/sites-available/{$basename}";
                $enabledPath = "/etc/nginx/sites-enabled/{$basename}";
                $connection->exec("sudo rm -f {$enabledPath}");
                $connection->exec("sudo rm -f {$configPath}");
            }

            $sslPath = "/etc/nginx/ssl/{$this->domain}";
            $connection->exec("sudo rm -rf {$sslPath}");

            $connection->exec('sudo systemctl reload nginx');

            $serverUser = config('server.user');
            if ($this->siteRoot && str_starts_with($this->siteRoot, "/home/{$serverUser}/")) {
                $connection->exec("rm -rf {$this->siteRoot}");
                Log::info("Removed site directory: {$this->siteRoot}");
            }

            $connection->disconnect();

            Log::info("Site {$this->domain} deleted successfully");

        } catch (\Throwable $e) {
            Log::error("Failed to delete site {$this->domain} from server: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        } finally {
            event(new ServerSitesUpdated($server));
        }
    }
}
