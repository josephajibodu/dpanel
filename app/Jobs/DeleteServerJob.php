<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Providers\ProviderManager;
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
    public function handle(ProviderManager $providerManager): void
    {
        Log::info("Starting deletion of server {$this->server->id} ({$this->server->name})");

        try {
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
