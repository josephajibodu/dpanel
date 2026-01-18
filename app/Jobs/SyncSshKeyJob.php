<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\SshKey;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSshKeyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(
        public SshKey $sshKey,
        public Server $server,
    ) {
        $this->onQueue('ssh');
    }

    public function handle(SshService $sshService): void
    {
        try {
            // Update status to syncing
            $this->sshKey->servers()->updateExistingPivot($this->server->id, [
                'status' => 'syncing',
            ]);

            // Connect to server
            $connection = $sshService->connect($this->server);

            // Append the key to authorized_keys if not already present
            $publicKey = trim($this->sshKey->public_key);
            $fingerprint = $this->sshKey->fingerprint;

            // Check if key already exists
            $serverUser = config('server.user');
            $checkCommand = sprintf(
                'grep -q "%s" /home/%s/.ssh/authorized_keys && echo "exists" || echo "not_found"',
                substr($publicKey, 0, 50), // Use first 50 chars for matching
                $serverUser
            );

            $result = trim($connection->exec($checkCommand));

            if ($result !== 'exists') {
                // Append the key
                $appendCommand = sprintf(
                    'echo "%s" >> /home/%s/.ssh/authorized_keys',
                    $publicKey,
                    $serverUser
                );
                $connection->exec($appendCommand);
            }

            $connection->disconnect();

            // Update pivot to synced
            $this->sshKey->servers()->updateExistingPivot($this->server->id, [
                'status' => 'synced',
                'synced_at' => now(),
            ]);

            Log::info("SSH key {$this->sshKey->id} synced to server {$this->server->id}");
        } catch (Throwable $e) {
            Log::error("Failed to sync SSH key {$this->sshKey->id} to server {$this->server->id}: {$e->getMessage()}");

            // Update status to failed
            $this->sshKey->servers()->updateExistingPivot($this->server->id, [
                'status' => 'failed',
            ]);

            throw $e;
        }
    }
}
