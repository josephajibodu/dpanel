<?php

namespace App\Services\Ssh;

use App\Exceptions\SshConnectionException;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class SshService
{
    private const CONNECT_TIMEOUT = 10;

    /**
     * Connect to a server via SSH as the default user (forge).
     */
    public function connect(Server $server): SshConnection
    {
        return $this->connectAs($server, 'forge');
    }

    /**
     * Connect to a server via SSH as root.
     * Used during initial provisioning before the forge user exists.
     */
    public function connectAsRoot(Server $server): SshConnection
    {
        return $this->connectAs($server, 'root');
    }

    /**
     * Connect to a server via SSH as a specific user.
     */
    public function connectAs(Server $server, string $username): SshConnection
    {
        $ssh = new SSH2(
            host: $server->ip_address,
            port: $server->ssh_port,
            timeout: self::CONNECT_TIMEOUT,
        );

        $ssh->setKeepAlive(30);

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

        try {
            $loginSuccess = $ssh->login($username, $key);
        } catch (\Throwable $e) {
            $errors = $ssh->getErrors();
            $errors = is_array($errors) ? $errors : [];

            Log::debug(
                'SSH login threw exception',
                [
                    'server_id' => $server->id,
                    'username' => $username,
                    'ip_address' => $server->ip_address,
                    'ssh_port' => $server->ssh_port,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'errors' => $errors,
                ]
            );

            throw new SshConnectionException(
                "SSH connection failed: {$e->getMessage()}",
                $server->ip_address,
                $server->ssh_port,
                $e
            );
        }

        if (! $loginSuccess) {
            $errors = $ssh->getErrors();
            $errors = is_array($errors) ? $errors : [];

            Log::debug(
                'SSH login failed',
                [
                    'server_id' => $server->id,
                    'username' => $username,
                    'ip_address' => $server->ip_address,
                    'ssh_port' => $server->ssh_port,
                    'errors' => $errors,
                ]
            );

            $errorMessage = "Failed to authenticate as '{$username}' to {$server->ip_address}";
            if (! empty($errors)) {
                $errorMessage .= '. Errors: '.implode('; ', $errors);
            }

            throw new SshConnectionException(
                $errorMessage,
                $server->ip_address,
                $server->ssh_port
            );
        }

        Log::debug(
            'SSH authenticated',
            [
                'server_id' => $server->id,
                'username' => $username,
                'ip_address' => $server->ip_address,
            ]
        );

        $server->touch('last_ssh_connection_at');

        return new SshConnection($ssh, $server, $username);
    }

    /**
     * Test if SSH connection to a server works as root.
     * Used during provisioning to wait for SSH to become available.
     */
    public function testConnectionAsRoot(Server $server): bool
    {
        return $this->testConnectionAs($server, 'root');
    }

    /**
     * Test if SSH connection to a server works as the default user.
     */
    public function testConnection(Server $server): bool
    {
        return $this->testConnectionAs($server, 'forge');
    }

    /**
     * Test if SSH connection to a server works as a specific user.
     */
    public function testConnectionAs(Server $server, string $username): bool
    {
        try {
            $connection = $this->connectAs($server, $username);
            $result = $connection->exec('echo "ok"');
            $connection->disconnect();

            return trim($result) === 'ok';
        } catch (\Throwable $e) {
            Log::debug(
                'SSH test connection failed',
                [
                    'server_id' => $server->id,
                    'username' => $username,
                    'ip_address' => $server->ip_address,
                    'ssh_port' => $server->ssh_port,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]
            );

            return false;
        }
    }
}
