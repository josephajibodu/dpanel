<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\Nginx\NginxConfigService;
use App\Services\SourceControlService;
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

    protected ?string $cloudflareDnsRecordId;

    public function __construct(Site $site)
    {
        $this->domain = $site->domain;
        $this->siteRoot = $site->rootPath();
        $this->serverId = $site->server_id;
        $this->siteId = $site->id;
        $this->repository = $site->repository;
        $this->sourceControlAccountId = $site->source_control_account_id;
        $this->cloudflareDnsRecordId = $site->cloudflare_dns_record_id;
    }

    public function handle(
        SshService $sshService,
        NginxConfigService $nginxService,
        SourceControlService $sourceControlService,
        CloudflareDnsService $cloudflareDns,
    ): void {
        Log::info("Deleting site {$this->domain}");

        if ($this->cloudflareDnsRecordId) {
            try {
                $cloudflareDns->deleteRecord($this->cloudflareDnsRecordId);
                Log::info("Deleted Cloudflare DNS record for {$this->domain}");
            } catch (\Throwable $e) {
                Log::warning("Failed to delete Cloudflare DNS record for {$this->domain}: {$e->getMessage()}");
            }
        }

        $site = Site::find($this->siteId);
        $server = \App\Models\Server::find($this->serverId);

        if (! $server) {
            Log::warning("Server {$this->serverId} not found, skipping site deletion");

            // Still try to delete the site from DB if it exists
            if ($site) {
                $site->delete();
            }

            return;
        }

        // Delete account-level SSH key from source control provider if no other sites use it
        if ($site && $site->sourceControlAccount && $server) {
            try {
                $sourceControlService->deleteAccountSshKeyIfUnused($server, $site->sourceControlAccount);
                Log::info("SSH key cleanup attempted for site {$this->domain}");
            } catch (\Throwable $e) {
                Log::warning("Failed to delete SSH key for site {$this->domain}: {$e->getMessage()}");
                // Continue with other cleanup even if SSH key deletion fails
            }
        }

        // Delete from database
        if ($site) {
            $site->delete();
        }

        // Perform server-side cleanup
        try {
            $connection = $sshService->connect($server);

            // Remove Nginx config
            $configPath = "/etc/nginx/sites-available/{$this->domain}";
            $enabledPath = "/etc/nginx/sites-enabled/{$this->domain}";

            $connection->exec("sudo rm -f {$enabledPath}");
            $connection->exec("sudo rm -f {$configPath}");

            // Remove SSL certificates if they exist
            $sslPath = "/etc/nginx/ssl/{$this->domain}";
            $connection->exec("sudo rm -rf {$sslPath}");

            // Reload Nginx
            $connection->exec('sudo systemctl reload nginx');

            // Remove site directory (with caution - only if it's in the expected location)
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
            // Don't rethrow - the site is already deleted from DB
            // The cleanup can be retried manually if needed
        }
    }
}
