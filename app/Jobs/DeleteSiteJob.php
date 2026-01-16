<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\NginxConfigService;
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

    public function __construct(Site $site)
    {
        // Store the values we need before the site is deleted
        $this->domain = $site->domain;
        $this->siteRoot = $site->rootPath();
        $this->serverId = $site->server_id;
        $this->siteId = $site->id;
    }

    public function handle(SshService $sshService, NginxConfigService $nginxService): void
    {
        Log::info("Deleting site {$this->domain}");

        // Get the server
        $server = \App\Models\Server::find($this->serverId);

        if (! $server) {
            Log::warning("Server {$this->serverId} not found, skipping site deletion");

            return;
        }

        // Delete from database first
        $site = Site::find($this->siteId);
        if ($site) {
            $site->delete();
        }

        try {
            $connection = $sshService->connect($server);

            // Remove Nginx config
            $configPath = "/etc/nginx/sites-available/{$this->domain}";
            $enabledPath = "/etc/nginx/sites-enabled/{$this->domain}";

            $connection->exec("sudo rm -f {$enabledPath}");
            $connection->exec("sudo rm -f {$configPath}");

            // Reload Nginx
            $connection->exec('sudo systemctl reload nginx');

            // Remove site directory (with caution)
            if ($this->siteRoot && str_starts_with($this->siteRoot, '/home/forge/')) {
                $connection->exec("rm -rf {$this->siteRoot}");
            }

            $connection->disconnect();

            Log::info("Site {$this->domain} deleted successfully");

        } catch (\Throwable $e) {
            Log::error("Failed to delete site {$this->domain} from server: {$e->getMessage()}");
            // Don't rethrow - the site is already deleted from DB
        }
    }
}
