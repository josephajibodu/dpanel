<?php

namespace App\Jobs;

use App\Actions\Database\EscapesShell;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Models\SshKey;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RevokeSshKeyJob implements ShouldQueue
{
    use Dispatchable, EscapesShell, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public int $sshKeyId;

    public string $publicKey;

    /**
     * The Linux user the key comes off: the server user, or an isolated
     * site's own user.
     */
    public string $systemUser;

    /**
     * The key is captured by value rather than serialized as a model, so the job
     * still runs when it was queued just before the key itself was deleted.
     */
    public function __construct(
        SshKey $sshKey,
        public Server $server,
        ?string $systemUser = null,
    ) {
        $this->sshKeyId = $sshKey->id;
        $this->publicKey = $sshKey->public_key;
        $this->systemUser = $systemUser ?? config('server.user');

        $this->onQueue('ssh');
    }

    public function handle(SshService $sshService): void
    {
        try {
            $this->placement()->update(['status' => 'revoking']);

            // Connect to server
            $connection = $sshService->connect($this->server);

            // Drop every line containing this key's base64 material as a whole field
            // (so lines with an options prefix match too). It's compared as a literal
            // string, so the stored key never reaches a regex or the shell unquoted.
            $authorizedKeysPath = "/home/{$this->systemUser}/.ssh/authorized_keys";
            $keyMaterial = $this->escapeForShell(explode(' ', trim($this->publicKey))[1] ?? '');
            $authorizedKeys = $this->escapeForShell($authorizedKeysPath);
            $tmpFile = $this->escapeForShell($authorizedKeysPath.'.tmp');

            $command = "awk -v key={$keyMaterial} '{ for (i = 1; i <= NF; i++) if (\$i == key) next } 1' {$authorizedKeys} > {$tmpFile}"
                ." && cat {$tmpFile} > {$authorizedKeys} && rm -f {$tmpFile}";

            if ($this->systemUser !== config('server.user')) {
                // A site user's home is closed to the server user, so the site
                // user edits its own file. A deleted site's user has none left.
                $user = $this->escapeForShell($this->systemUser);
                $asSiteUser = "sudo -u {$user} -H bash -c ".$this->escapeForShell("[ -f {$authorizedKeys} ] || exit 0; {$command}");
                $command = "if id -u {$user} >/dev/null 2>&1; then {$asSiteUser}; fi";
            }

            $connection->exec($command);
            $connection->disconnect();

            $this->placement()->delete();

            Log::info("SSH key {$this->sshKeyId} revoked from {$this->systemUser} on server {$this->server->id}");
        } catch (Throwable $e) {
            Log::error("Failed to revoke SSH key {$this->sshKeyId} from server {$this->server->id}: {$e->getMessage()}");

            $this->placement()->update(['status' => 'failed']);

            throw $e;
        }
    }

    /**
     * @return Builder<ServerSshKey>
     */
    private function placement(): Builder
    {
        return ServerSshKey::query()->placement($this->sshKeyId, $this->server->id, $this->systemUser);
    }
}
