<?php

namespace App\Services\Ssh;

use App\Models\Server;
use Illuminate\Support\Facades\Log;

class SshRetryHandler
{
    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * Wait for SSH to become available with exponential backoff.
     * Used after server creation when SSH isn't immediately ready.
     */
    public function waitForSsh(
        Server $server,
        string $username = 'root',
        int $maxAttempts = 20,
        int $initialDelay = 5,
    ): bool {
        $delay = $initialDelay;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            Log::info("SSH attempt {$attempt}/{$maxAttempts} for server {$server->id} as {$username}");

            if ($this->sshService->testConnectionAs($server, $username)) {
                Log::info("SSH connection established for server {$server->id} as {$username}");

                return true;
            }

            if ($attempt < $maxAttempts) {
                sleep($delay);
                $delay = min((int) ($delay * 1.5), 30); // Max 30 second delay
            }
        }

        Log::error("SSH connection failed after {$maxAttempts} attempts for server {$server->id} as {$username}");

        return false;
    }
}
