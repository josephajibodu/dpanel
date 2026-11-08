<?php

namespace App\Actions\Servers;

use App\Actions\Database\EscapesShell;
use App\Models\Server;
use App\Services\Ssh\SshService;

/**
 * Take the app's own SSH key off a server, so the app can no longer log in
 * once the server is disconnected. Used for custom servers, where the
 * machine keeps running after it is removed from the app.
 *
 * The key is removed from root (where the connect command put it) and from
 * the server user (provisioning copied root's keys there). Every other key
 * on the server, including the team's synced keys, is left alone.
 */
class RevokeFlitopsAccessAction
{
    use EscapesShell;

    public function __construct(
        private SshService $sshService,
    ) {}

    public function execute(Server $server): void
    {
        $publicKey = $server->credential('public_key')?->value;
        $keyMaterial = explode(' ', trim((string) $publicKey))[1] ?? '';

        if ($keyMaterial === '' || ! $server->ip_address) {
            return;
        }

        $connection = $this->sshService->connectAsRoot($server);

        try {
            $connection->exec($this->removeKeyCommand($keyMaterial, [
                '/home/'.config('server.user').'/.ssh/authorized_keys',
                '/root/.ssh/authorized_keys',
            ]));
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Drop every line holding the key's base64 material as a whole field, so
     * lines with an options prefix match too. The key is passed to awk as a
     * literal string, never as a regex. Writing back with `cat >` keeps the
     * file's owner and permissions.
     *
     * @param  array<int, string>  $paths
     */
    private function removeKeyCommand(string $keyMaterial, array $paths): string
    {
        $key = $this->escapeForShell($keyMaterial);

        return collect($paths)
            ->map(function (string $path) use ($key): string {
                $file = $this->escapeForShell($path);
                $tmpFile = $this->escapeForShell($path.'.tmp');

                return "if [ -f {$file} ]; then awk -v key={$key} '{ for (i = 1; i <= NF; i++) if (\$i == key) next } 1' {$file} > {$tmpFile}"
                    ." && cat {$tmpFile} > {$file} && rm -f {$tmpFile}; fi";
            })
            ->implode(' && ');
    }
}
