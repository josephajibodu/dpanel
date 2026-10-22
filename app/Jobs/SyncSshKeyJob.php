<?php

namespace App\Jobs;

use App\Actions\Database\EscapesShell;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Models\Site;
use App\Models\SshKey;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Adds a key to the server user's authorized_keys (full access to the
 * server), or, when a site is given, to that isolated site's own user
 * (SSH and SFTP access to that one site).
 */
class SyncSshKeyJob implements ShouldQueue
{
    use Dispatchable, EscapesShell, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(
        public SshKey $sshKey,
        public Server $server,
        public ?Site $site = null,
    ) {
        $this->onQueue('ssh');
    }

    public function handle(SshService $sshService): void
    {
        $systemUser = $this->site?->system_user ?? config('server.user');

        try {
            if ($this->site && (! $this->site->isIsolated() || $this->site->server_id !== $this->server->id)) {
                throw new RuntimeException("Site {$this->site->id} isn't an isolated site on server {$this->server->id}.");
            }

            $this->updatePlacement($systemUser, ['status' => 'syncing']);

            $connection = $sshService->connect($this->server);

            $publicKey = $this->escapeForShell(trim($this->sshKey->public_key));

            if ($this->site) {
                // The site user's home is closed to the server user, so the
                // site user adds the key to its own authorized_keys.
                $connection->exec($this->site->commandAsSystemUser(
                    'mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && '
                    ."{ grep -qxF -- {$publicKey} ~/.ssh/authorized_keys || printf '%s\\n' {$publicKey} >> ~/.ssh/authorized_keys; }"
                ));
            } else {
                // Append the key to authorized_keys if the exact line isn't already present
                $authorizedKeys = $this->escapeForShell('/home/'.config('server.user').'/.ssh/authorized_keys');

                $checkCommand = "grep -qxF -- {$publicKey} {$authorizedKeys} && echo \"exists\" || echo \"not_found\"";

                $result = trim($connection->exec($checkCommand));

                if ($result !== 'exists') {
                    $connection->exec("printf '%s\\n' {$publicKey} >> {$authorizedKeys}");
                }
            }

            $connection->disconnect();

            $this->updatePlacement($systemUser, [
                'status' => 'synced',
                'synced_at' => now(),
            ]);

            Log::info("SSH key {$this->sshKey->id} synced to {$systemUser} on server {$this->server->id}");
        } catch (Throwable $e) {
            Log::error("Failed to sync SSH key {$this->sshKey->id} to {$systemUser} on server {$this->server->id}: {$e->getMessage()}");

            $this->updatePlacement($systemUser, ['status' => 'failed']);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updatePlacement(string $systemUser, array $attributes): void
    {
        ServerSshKey::query()
            ->placement($this->sshKey->id, $this->server->id, $systemUser)
            ->update($attributes);
    }
}
