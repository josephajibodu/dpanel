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

        try {
            $output = $this->ssh->exec($command);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'close the channel')) {
                $this->ssh->reset();

                throw new \RuntimeException(
                    "SSH channel was left open by a previous command (likely a timeout). Connection has been reset. Original error: {$e->getMessage()}",
                    0,
                    $e,
                );
            }

            throw $e;
        }

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
     *
     * phpseclib delivers output in chunks aligned to TCP/SSH packet boundaries,
     * not line boundaries, so a single logical line of remote output can arrive
     * split across multiple callback invocations. We buffer partial output and
     * only emit once a newline is seen, so the consumer receives exactly one
     * call per complete line.
     */
    public function execWithOutput(
        string $command,
        callable $onOutput,
        int $timeout = 600,
    ): int {
        $this->ssh->setTimeout($timeout);

        $buffer = '';

        $this->ssh->exec($command, function ($chunk) use ($onOutput, &$buffer) {
            $buffer .= $chunk;

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                if (str_ends_with($line, "\r")) {
                    $line = substr($line, 0, -1);
                }

                $onOutput($line);
            }
        });

        if ($buffer !== '') {
            if (str_ends_with($buffer, "\r")) {
                $buffer = substr($buffer, 0, -1);
            }
            $onOutput($buffer);
        }

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
