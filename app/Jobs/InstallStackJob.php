<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\ProvisioningScriptService;
use App\Services\Ssh\SshRetryHandler;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class InstallStackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes

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
        SshService $sshService,
        SshRetryHandler $retryHandler,
        ProvisioningScriptService $scriptService,
    ): void {
        Log::info("Starting stack installation for server {$this->server->id}");

        try {
            // Wait for SSH to become available
            if (! $retryHandler->waitForSsh($this->server)) {
                throw new \Exception('SSH connection not available after maximum retries');
            }

            // Connect to server
            $connection = $sshService->connect($this->server);

            try {
                // Generate and upload provisioning script
                $script = $scriptService->generate($this->server);

                // Upload script
                $connection->upload($script, '/tmp/provision.sh');

                // Make executable
                $connection->sudo('chmod +x /tmp/provision.sh');

                // Execute provisioning script with streaming output
                $exitCode = $connection->execWithOutput(
                    'sudo /tmp/provision.sh',
                    function ($line) {
                        Log::debug("Provision output: {$line}");
                        // Here we could broadcast progress updates
                    },
                    timeout: 1800,
                );

                if ($exitCode !== 0) {
                    throw new \Exception("Provisioning script failed with exit code {$exitCode}");
                }

                // Cleanup
                $connection->sudo('rm -f /tmp/provision.sh');

                // Mark server as active
                $this->server->update([
                    'status' => ServerStatus::Active,
                    'provisioned_at' => now(),
                ]);

                Log::info("Server {$this->server->id} provisioned successfully");

            } finally {
                $connection->disconnect();
            }

        } catch (\Exception $e) {
            Log::error("Failed to install stack on server {$this->server->id}: {$e->getMessage()}");

            $this->server->update(['status' => ServerStatus::Error]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("InstallStackJob failed for server {$this->server->id}: {$exception->getMessage()}");

        $this->server->update(['status' => ServerStatus::Error]);
    }
}
