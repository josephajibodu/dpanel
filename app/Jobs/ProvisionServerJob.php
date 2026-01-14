<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Providers\ProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProvisionServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes

    public int $backoff = 30;

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
        Log::info("Starting provisioning for server {$this->server->id} ({$this->server->name})");

        // Update status to creating
        $this->server->update(['status' => ServerStatus::Creating]);

        try {
            $provider = $providerManager->forAccount($this->server->providerAccount);

            // Get the SSH key ID from meta
            $sshKeyId = $this->server->meta['provider_ssh_key_id'] ?? null;
            if (! $sshKeyId) {
                throw new \Exception('No provider SSH key ID found for server');
            }

            // Create server at provider
            $result = $provider->createServer(
                name: $this->server->name,
                size: $this->server->size,
                region: $this->server->region,
                sshKeyId: $sshKeyId,
            );

            // Store provider server ID
            $this->server->update([
                'provider_server_id' => $result->id,
            ]);

            Log::info("Server created at provider with ID {$result->id}");

            // Poll until server is active and has IP
            $this->waitForServerActive($provider, $result->id);

        } catch (\Exception $e) {
            Log::error("Failed to provision server {$this->server->id}: {$e->getMessage()}");

            $this->server->update(['status' => ServerStatus::Error]);

            throw $e;
        }
    }

    /**
     * Poll provider until server is active and has an IP address.
     */
    private function waitForServerActive($provider, string $providerServerId): void
    {
        $maxAttempts = 60; // 5 minutes with 5 second intervals
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            $status = $provider->getServerStatus($providerServerId);

            Log::debug("Server status check {$attempt}: {$status->status}, IP: {$status->ipAddress}");

            if ($status->isActive && $status->ipAddress) {
                // Server is ready!
                $this->server->update([
                    'ip_address' => $status->ipAddress,
                    'private_ip_address' => $status->privateIpAddress,
                    'status' => ServerStatus::Provisioning,
                ]);

                Log::info("Server {$this->server->id} is active at {$status->ipAddress}");

                // Dispatch stack installation job
                InstallStackJob::dispatch($this->server);

                return;
            }

            sleep(5);
        }

        throw new \Exception('Timeout waiting for server to become active');
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProvisionServerJob failed for server {$this->server->id}: {$exception->getMessage()}");

        $this->server->update(['status' => ServerStatus::Error]);
    }
}
