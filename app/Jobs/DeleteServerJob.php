<?php

namespace App\Jobs;

use App\Actions\Sites\CleanupSiteExternalResourcesAction;
use App\Enums\Provider;
use App\Enums\ServerStatus;
use App\Events\ServerDeleted;
use App\Models\Server;
use App\Services\Providers\ProviderManager;
use App\Services\SourceControlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeleteServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Server $server
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ProviderManager $providerManager,
        SourceControlService $sourceControlService,
        CleanupSiteExternalResourcesAction $cleanupAction,
    ): void {
        Log::info("Starting deletion of server {$this->server->id} ({$this->server->name})");

        try {
            // Tear down external resources for each site BEFORE destroying the
            // VPS, since those resources (Cloudflare A records) are tracked by
            // the site's domain rows that the DB cascade will wipe. We skip
            // per-site GitHub SSH key cleanup here because the server-wide
            // sweep below covers it in one pass.
            $this->server->load('sites.domains');

            foreach ($this->server->sites as $site) {
                $recordIds = $site->domains
                    ->pluck('cloudflare_dns_record_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                try {
                    $cleanupAction->execute(
                        cloudflareDnsRecordIds: $recordIds,
                        server: $this->server,
                        sourceControlAccount: null,
                        domain: $site->domain,
                        cleanupGithubSshKey: false,
                    );
                } catch (\Exception $e) {
                    Log::warning("Failed to clean up external resources for site {$site->domain}: {$e->getMessage()}");
                }
            }

            if ($this->server->provider !== Provider::Custom) {
                $provider = $providerManager->forAccount($this->server->providerAccount);

                // Delete server at provider if it exists
                if ($this->server->provider_server_id) {
                    try {
                        $provider->deleteServer($this->server->provider_server_id);
                        Log::info("Deleted server at provider: {$this->server->provider_server_id}");
                    } catch (\Exception $e) {
                        // Server might already be deleted
                        Log::warning("Failed to delete server at provider (may already be deleted): {$e->getMessage()}");
                    }
                }

                // Delete SSH key at provider
                $sshKeyId = $this->server->meta['provider_ssh_key_id'] ?? null;
                if ($sshKeyId) {
                    try {
                        $provider->deleteSshKey($sshKeyId);
                        Log::info("Deleted SSH key at provider: {$sshKeyId}");
                    } catch (\Exception $e) {
                        Log::warning("Failed to delete SSH key at provider: {$e->getMessage()}");
                    }
                }
            }

            // Delete account-level SSH keys from GitHub accounts
            try {
                $sourceControlService->deleteAllAccountSshKeysForServer($this->server);
                Log::info("Deleted GitHub account SSH keys for server {$this->server->id}");
            } catch (\Exception $e) {
                Log::warning("Failed to delete GitHub account SSH keys: {$e->getMessage()}");
                // Continue with server deletion even if SSH key cleanup fails
            }

            // Broadcast deletion before removing the record so list subscribers can update
            event(new ServerDeleted(
                serverId: $this->server->id,
                teamId: $this->server->team_id,
            ));

            // Delete local server record and related data
            $this->server->delete();

            Log::info("Server {$this->server->id} deleted successfully");

        } catch (\Exception $e) {
            Log::error("Failed to delete server {$this->server->id}: {$e->getMessage()}");

            $this->server->update(['status' => ServerStatus::Error]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("DeleteServerJob failed for server {$this->server->id}: {$exception->getMessage()}");

        $this->server->update(['status' => ServerStatus::Error]);
    }
}
