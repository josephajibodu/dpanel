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

        $this->throwIfTimedOut($command, $timeout);

        if ($this->exitStatus() !== 0) {
            throw new SshCommandException(
                command: $command,
                exitCode: $this->exitStatus(),
                output: $output,
                stderr: $this->ssh->getStdError(),
                secrets: $this->server->secretValues(),
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

        $this->throwIfTimedOut($command, $timeout);

        return $this->exitStatus();
    }

    /**
     * Fail a command that ran out of time instead of reading an exit code for
     * it. phpseclib keeps the previous command's exit code when the current
     * one reports none, so a timed-out command would otherwise look like it
     * succeeded. The timed-out command's channel is closed so the next
     * command on this connection can run.
     */
    private function throwIfTimedOut(string $command, int $timeout): void
    {
        if (! $this->ssh->isTimeout()) {
            return;
        }

        $this->ssh->reset();

        throw new \RuntimeException(
            "SSH command timed out after {$timeout} seconds: ".SshCommandException::redact($command, $this->server->secretValues()),
        );
    }

    /**
     * The exit code of the last command, or -1 when the server reported none.
     */
    private function exitStatus(): int
    {
        $exitStatus = $this->ssh->getExitStatus();

        return is_int($exitStatus) ? $exitStatus : -1;
    }

    /**
     * Stderr captured by the most recently completed exec()/execWithOutput() call.
     */
    public function lastStdError(): string
    {
        return $this->ssh->getStdError();
    }

    /**
     * The server's passwords, for scrubbing from a failed command's error.
     *
     * @return array<int, string>
     */
    public function secretValues(): array
    {
        return $this->server->secretValues();
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
     *
     * SFTP opens a second connection, so the server's host key is checked
     * again before logging in.
     */
    private function getSftp(): SFTP
    {
        if ($this->sftp === null) {
            $this->sftp = app()->makeWith(SFTP::class, [
                'host' => $this->server->ip_address,
                'port' => $this->server->ssh_port,
            ]);

            app(HostKeyVerifier::class)->verify($this->sftp, $this->server);

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
