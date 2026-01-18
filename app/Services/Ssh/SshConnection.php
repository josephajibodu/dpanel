<?php

namespace App\Services\Ssh;

use App\Exceptions\SshCommandException;
use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

class SshConnection
{
    private ?SFTP $sftp = null;

    public function __construct(
        private SSH2 $ssh,
        private Server $server,
        private string $username = 'artisan',
    ) {}

    /**
     * Execute command and return output.
     */
    public function exec(string $command, int $timeout = 30): string
    {
        $this->ssh->setTimeout($timeout);

        $output = $this->ssh->exec($command);

        if ($this->ssh->getExitStatus() !== 0) {
            throw new SshCommandException(
                command: $command,
                exitCode: $this->ssh->getExitStatus() ?? -1,
                output: $output,
                stderr: $this->ssh->getStdError(),
            );
        }

        return $output;
    }

    /**
     * Execute command with streaming output callback.
     * Used for deployment logs and provisioning progress.
     */
    public function execWithOutput(
        string $command,
        callable $onOutput,
        int $timeout = 600,
    ): int {
        $this->ssh->setTimeout($timeout);

        // Use exec with callback for streaming
        $this->ssh->exec($command, function ($output) use ($onOutput) {
            // Split into lines and call callback for each
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                if ($line !== '') {
                    $onOutput($line);
                }
            }
        });

        return $this->ssh->getExitStatus() ?? 0;
    }

    /**
     * Upload file content to server.
     */
    public function upload(string $content, string $remotePath): void
    {
        $sftp = $this->getSftp();

        if (! $sftp->put($remotePath, $content)) {
            throw new \RuntimeException("Failed to upload to {$remotePath}");
        }
    }

    /**
     * Download file content from server.
     */
    public function download(string $remotePath): string
    {
        $sftp = $this->getSftp();

        $content = $sftp->get($remotePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to download {$remotePath}");
        }

        return $content;
    }

    /**
     * Execute command as root using sudo.
     */
    public function sudo(string $command, int $timeout = 30): string
    {
        return $this->exec("sudo {$command}", $timeout);
    }

    /**
     * Check if a file exists on the server.
     */
    public function fileExists(string $path): bool
    {
        try {
            $this->exec("test -f {$path}");

            return true;
        } catch (SshCommandException) {
            return false;
        }
    }

    /**
     * Check if a directory exists on the server.
     */
    public function directoryExists(string $path): bool
    {
        try {
            $this->exec("test -d {$path}");

            return true;
        } catch (SshCommandException) {
            return false;
        }
    }

    /**
     * Get the SFTP connection using the same credentials as SSH.
     */
    private function getSftp(): SFTP
    {
        if ($this->sftp === null) {
            $this->sftp = new SFTP(
                $this->server->ip_address,
                $this->server->ssh_port
            );

            $privateKeyCredential = $this->server->credentials()
                ->where('type', 'private_key')
                ->first();

            $key = PublicKeyLoader::load($privateKeyCredential->value);

            // Use the same username as the SSH connection
            if (! $this->sftp->login($this->username, $key)) {
                throw new \RuntimeException("Failed to authenticate SFTP connection as {$this->username}");
            }
        }

        return $this->sftp;
    }

    public function disconnect(): void
    {
        $this->ssh->disconnect();
        $this->sftp?->disconnect();
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
