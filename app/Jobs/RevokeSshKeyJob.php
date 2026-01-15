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

class RevokeSshKeyJob implements ShouldQueue
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
            // Update status to revoking
            $this->sshKey->servers()->updateExistingPivot($this->server->id, [
                'status' => 'revoking',
            ]);

            // Connect to server
            $connection = $sshService->connect($this->server);

            // Remove the key from authorized_keys using the fingerprint
            // We use a unique portion of the key to identify and remove it
            $publicKey = trim($this->sshKey->public_key);

            // Escape special characters for sed
            $escapedKey = str_replace(['/', '&', '\\'], ['\\/', '\\&', '\\\\'], $publicKey);

            // Remove the line containing this key
            $command = sprintf(
                'sed -i "\\|%s|d" /home/forge/.ssh/authorized_keys',
                substr($escapedKey, 0, 50) // Use first 50 chars for matching
            );

            $connection->exec($command);
            $connection->disconnect();

            // Remove pivot record
            $this->sshKey->servers()->detach($this->server->id);

            Log::info("SSH key {$this->sshKey->id} revoked from server {$this->server->id}");
        } catch (Throwable $e) {
            Log::error("Failed to revoke SSH key {$this->sshKey->id} from server {$this->server->id}: {$e->getMessage()}");

            // Update status to failed
            $this->sshKey->servers()->updateExistingPivot($this->server->id, [
                'status' => 'failed',
            ]);

            throw $e;
        }
    }
}
