<?php

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Enums\ProvisioningStep;
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
     * Collected data from provisioning script output.
     *
     * @var array<string, string>
     */
    private array $collectedData = [];

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

        // Set initial provisioning step
        $this->updateStep(ProvisioningStep::WaitingForServer);

        try {
            // Wait for SSH to become available (as root during provisioning)
            if (! $retryHandler->waitForSsh($this->server, 'root')) {
                throw new \Exception('SSH connection not available after maximum retries');
            }

            // Connect to server as root for provisioning
            $connection = $sshService->connectAsRoot($this->server);

            try {
                // Generate and upload provisioning script
                $script = $scriptService->generate($this->server);

                // Upload script
                $connection->upload($script, '/tmp/provision.sh');

                // Make executable (no sudo needed, we're root)
                $connection->exec('chmod +x /tmp/provision.sh');

                // Execute provisioning script with streaming output
                $exitCode = $connection->execWithOutput(
                    '/tmp/provision.sh',
                    function ($line) {
                        Log::debug("Provision output: {$line}");

                        // Check if this line is a step marker
                        $stepNumber = ProvisioningScriptService::parseStepMarker($line);
                        if ($stepNumber !== null) {
                            $step = ProvisioningStep::tryFrom($stepNumber);
                            if ($step !== null) {
                                $this->updateStep($step);
                                Log::info("Server {$this->server->id} provisioning step: {$step->label()}");
                            }

                            return;
                        }

                        // Check if this line is a data marker
                        $data = ProvisioningScriptService::parseDataMarker($line);
                        if ($data !== null) {
                            $this->collectedData[$data['key']] = $data['value'];
                            Log::info("Server {$this->server->id} collected data: {$data['key']}");
                        }
                    },
                    timeout: 1800,
                );

                if ($exitCode !== 0) {
                    throw new \Exception("Provisioning script failed with exit code {$exitCode}");
                }

                // Cleanup
                $connection->exec('rm -f /tmp/provision.sh');

                // Mark server as active with finished step and collected data
                $updateData = [
                    'status' => ServerStatus::Active,
                    'provisioning_step' => ProvisioningStep::Finished,
                    'connection_status' => ConnectionStatus::Successful,
                    'provisioned_at' => now(),
                ];

                // Add collected ubuntu_version if available
                if (isset($this->collectedData['ubuntu_version'])) {
                    $updateData['ubuntu_version'] = $this->collectedData['ubuntu_version'];
                }

                // Add collected local_public_key if available
                if (isset($this->collectedData['local_public_key'])) {
                    $updateData['local_public_key'] = $this->collectedData['local_public_key'];
                }

                $this->server->update($updateData);

                Log::info("Server {$this->server->id} provisioned successfully", [
                    'ubuntu_version' => $this->collectedData['ubuntu_version'] ?? 'unknown',
                    'has_local_public_key' => isset($this->collectedData['local_public_key']),
                ]);

            } finally {
                $connection->disconnect();
            }

        } catch (\Throwable $e) {
            Log::error(
                "Failed to install stack on server {$this->server->id}",
                [
                    'server_id' => $this->server->id,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'previous' => $e->getPrevious() ? [
                        'exception' => get_class($e->getPrevious()),
                        'message' => $e->getPrevious()->getMessage(),
                    ] : null,
                ]
            );

            $this->server->update(['status' => ServerStatus::Error]);

            throw $e;
        }
    }

    /**
     * Update the provisioning step for the server.
     */
    private function updateStep(ProvisioningStep $step): void
    {
        $this->server->update(['provisioning_step' => $step]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error(
            "InstallStackJob failed for server {$this->server->id}",
            [
                'server_id' => $this->server->id,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'previous' => $exception->getPrevious() ? [
                    'exception' => get_class($exception->getPrevious()),
                    'message' => $exception->getPrevious()->getMessage(),
                    'trace' => $exception->getPrevious()->getTraceAsString(),
                ] : null,
            ]
        );

        $this->server->update(['status' => ServerStatus::Error]);
    }
}
