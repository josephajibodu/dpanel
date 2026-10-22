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

    public int $sshKeyId;

    public string $publicKey;

    /**
     * The key is captured by value rather than serialized as a model, so the job
     * still runs when it was queued just before the key itself was deleted.
     */
    public function __construct(
        SshKey $sshKey,
        public Server $server,
    ) {
        $this->sshKeyId = $sshKey->id;
        $this->publicKey = $sshKey->public_key;

        $this->onQueue('ssh');
    }

    public function handle(SshService $sshService): void
    {
        try {
            // Update status to revoking
            $this->server->sshKeys()->updateExistingPivot($this->sshKeyId, [
                'status' => 'revoking',
            ]);

            // Connect to server
            $connection = $sshService->connect($this->server);

            // Drop every line containing this key's base64 material as a whole field
            // (so lines with an options prefix match too). It's compared as a literal
            // string, so the stored key never reaches a regex or the shell unquoted.
            $authorizedKeysPath = '/home/'.config('server.user').'/.ssh/authorized_keys';
            $keyMaterial = escapeshellarg(explode(' ', trim($this->publicKey))[1] ?? '');
            $authorizedKeys = escapeshellarg($authorizedKeysPath);
            $tmpFile = escapeshellarg($authorizedKeysPath.'.tmp');

            $command = "awk -v key={$keyMaterial} '{ for (i = 1; i <= NF; i++) if (\$i == key) next } 1' {$authorizedKeys} > {$tmpFile}"
                ." && cat {$tmpFile} > {$authorizedKeys} && rm -f {$tmpFile}";

            $connection->exec($command);
            $connection->disconnect();

            // Remove pivot record
            $this->server->sshKeys()->detach($this->sshKeyId);

            Log::info("SSH key {$this->sshKeyId} revoked from server {$this->server->id}");
        } catch (Throwable $e) {
            Log::error("Failed to revoke SSH key {$this->sshKeyId} from server {$this->server->id}: {$e->getMessage()}");

            // Update status to failed
            $this->server->sshKeys()->updateExistingPivot($this->sshKeyId, [
                'status' => 'failed',
            ]);

            throw $e;
        }
    }
}
