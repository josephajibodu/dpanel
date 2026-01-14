<?php

namespace App\Services\Ssh;

use App\Exceptions\SshConnectionException;
use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class SshService
{
    private const DEFAULT_TIMEOUT = 30;

    private const CONNECT_TIMEOUT = 10;

    /**
     * Connect to a server via SSH.
     */
    public function connect(Server $server): SshConnection
    {
        $ssh = new SSH2(
            host: $server->ip_address,
            port: $server->ssh_port,
            timeout: self::CONNECT_TIMEOUT,
        );

        // Configure connection
        $ssh->setKeepAlive(30);

        // Load private key
        $privateKeyCredential = $server->credentials()
            ->where('type', 'private_key')
            ->first();

        if (! $privateKeyCredential) {
            throw new SshConnectionException(
                'No private key found for server',
                $server->ip_address,
                $server->ssh_port
            );
        }

        $key = PublicKeyLoader::load($privateKeyCredential->value);

        // Try to authenticate as forge user first
        if (! $ssh->login('forge', $key)) {
            // Fallback to root during provisioning
            if (! $ssh->login('root', $key)) {
                throw new SshConnectionException(
                    "Failed to authenticate to {$server->ip_address}",
                    $server->ip_address,
                    $server->ssh_port
                );
            }
        }

        // Update last connection timestamp
        $server->touch('last_ssh_connection_at');

        return new SshConnection($ssh, $server);
    }

    /**
     * Test if SSH connection to a server works.
     */
    public function testConnection(Server $server): bool
    {
        try {
            $connection = $this->connect($server);
            $result = $connection->exec('echo "ok"');
            $connection->disconnect();

            return trim($result) === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }
}
