<?php

namespace App\Exceptions;

use Exception;

/**
 * A remote command that exited non-zero.
 *
 * Commands often carry secrets (storage keys in env prefixes, database
 * passwords in flags or SQL), and Laravel logs the message of any uncaught
 * exception. So the command and its output are scrubbed here, before they
 * reach the message or the public properties: the given secrets are replaced
 * exactly, and common secret-bearing patterns are replaced as a fallback.
 */
class SshCommandException extends Exception
{
    public readonly string $command;

    public readonly string $output;

    public readonly ?string $stderr;

    /**
     * @param  array<int, string>  $secrets  Exact values to remove, such as the server's passwords.
     */
    public function __construct(
        string $command,
        public readonly int $exitCode,
        string $output,
        ?string $stderr = null,
        array $secrets = [],
    ) {
        $this->command = self::redact($command, $secrets);
        $this->output = self::redact($output, $secrets);
        $this->stderr = $stderr === null ? null : self::redact($stderr, $secrets);

        $message = "SSH command failed with exit code {$exitCode}: {$this->command}";

        if ($this->stderr) {
            $message .= "\nSTDERR: {$this->stderr}";
        }

        if ($this->output) {
            $message .= "\nOutput: {$this->output}";
        }

        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $secrets
     */
    public static function redact(string $text, array $secrets = []): string
    {
        $secrets = array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''));

        if ($secrets !== []) {
            $text = str_replace($secrets, '[redacted]', $text);
        }

        return preg_replace(
            [
                '/\b([A-Za-z0-9_-]*(?:KEY|SECRET|TOKEN|PASSWORD)[A-Za-z0-9_]*=)\S+/i',
                "/\b(IDENTIFIED BY|PASSWORD)\s+'[^']*'/i",
            ],
            ['$1[redacted]', "$1 '[redacted]'"],
            $text,
        ) ?? $text;
    }
}
