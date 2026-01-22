<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\NginxConfigService;
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

    public function __construct(Site $site)
    {
        // Store the values we need before the site is deleted
        $this->domain = $site->domain;
        $this->siteRoot = $site->rootPath();
        $this->serverId = $site->server_id;
        $this->siteId = $site->id;
        $this->repository = $site->repository;
        $this->sourceControlAccountId = $site->source_control_account_id;
    }

    public function handle(
        SshService $sshService,
        NginxConfigService $nginxService,
        SourceControlService $sourceControlService,
    ): void {
        Log::info("Deleting site {$this->domain}");

        // Get the site and server before deletion
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

        // Delete deploy key from source control provider if applicable
        if ($site && $site->repository && $site->sourceControlAccount) {
            try {
                $sourceControlService->deleteDeployKey($site);
                Log::info("Deploy key cleanup attempted for site {$this->domain}");
            } catch (\Throwable $e) {
                Log::warning("Failed to delete deploy key for site {$this->domain}: {$e->getMessage()}");
                // Continue with other cleanup even if deploy key deletion fails
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
