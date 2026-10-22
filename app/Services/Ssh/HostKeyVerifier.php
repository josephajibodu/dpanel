<?php

namespace App\Services\Ssh;

use App\Exceptions\SshConnectionException;
use App\Exceptions\SshHostKeyMismatchException;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use phpseclib3\Common\Functions\Strings;
use phpseclib3\Net\SSH2;

/**
 * Trust-on-first-use host key checking, the same model as OpenSSH's
 * known_hosts: the first key a server presents is recorded, and every later
 * connection must present that same key.
 */
class HostKeyVerifier
{
    /**
     * Compare the key the server presents against the recorded one. This runs
     * after the key exchange but before any credentials are sent; on a
     * mismatch the connection is dropped.
     *
     * @return array{type: string, fingerprint: string}
     *
     * @throws SshHostKeyMismatchException
     */
    public function verify(SSH2 $ssh, Server $server): array
    {
        $hostKey = $ssh->getServerPublicHostKey();

        if (! is_string($hostKey)) {
            $ssh->disconnect();

            throw new SshConnectionException(
                "Couldn't verify the server's identity (its SSH host key), so the connection was stopped",
                $server->ip_address,
                $server->ssh_port,
            );
        }

        $presented = $this->describe($hostKey);

        if ($server->hasPinnedHostKey() && ! hash_equals($server->host_key_fingerprint, $presented['fingerprint'])) {
            $ssh->disconnect();

            Log::warning('SSH host key mismatch', [
                'server_id' => $server->id,
                'ip_address' => $server->ip_address,
                'ssh_port' => $server->ssh_port,
                'expected_fingerprint' => $server->host_key_fingerprint,
                'presented_fingerprint' => $presented['fingerprint'],
            ]);

            throw new SshHostKeyMismatchException($server, $presented['fingerprint']);
        }

        return $presented;
    }

    /**
     * Turn phpseclib's "<algorithm> <base64 key>" string into the key type and
     * an OpenSSH-style SHA256 fingerprint, matching `ssh-keygen -lf`.
     *
     * @return array{type: string, fingerprint: string}
     */
    public function describe(string $hostKey): array
    {
        [, $encodedKey] = explode(' ', $hostKey, 2);
        $keyBlob = base64_decode($encodedKey);
        $unread = $keyBlob;
        [$type] = Strings::unpackSSH2('s', $unread);

        return [
            'type' => $type,
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $keyBlob, true)), '='),
        ];
    }
}
