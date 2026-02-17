<?php

namespace App\Jobs;

use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Provisioning\StackInstaller;
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
        StackInstaller $stackInstaller,
    ): void {
        Log::info("Starting stack installation for server {$this->server->id}");

        $this->server->update(['provisioning_step' => ProvisioningStep::WaitingForServer]);

        try {
            if (! $retryHandler->waitForSsh($this->server, 'root')) {
                throw new \Exception('SSH connection not available after maximum retries');
            }

            $connection = $sshService->connectAsRoot($this->server);

            try {
                $stackInstaller->install($this->server, $connection);
                Log::info("Server {$this->server->id} provisioned successfully");
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
