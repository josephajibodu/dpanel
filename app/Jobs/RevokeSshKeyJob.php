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

            // Drop every line containing this key's base64 material as a whole field
            // (so lines with an options prefix match too). It's compared as a literal
            // string, so the stored key never reaches a regex or the shell unquoted.
            $authorizedKeysPath = '/home/'.config('server.user').'/.ssh/authorized_keys';
            $keyMaterial = escapeshellarg(explode(' ', trim($this->sshKey->public_key))[1] ?? '');
            $authorizedKeys = escapeshellarg($authorizedKeysPath);
            $tmpFile = escapeshellarg($authorizedKeysPath.'.tmp');

            $command = "awk -v key={$keyMaterial} '{ for (i = 1; i <= NF; i++) if (\$i == key) next } 1' {$authorizedKeys} > {$tmpFile}"
                ." && cat {$tmpFile} > {$authorizedKeys} && rm -f {$tmpFile}";

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
