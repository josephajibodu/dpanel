<?php

namespace App\Exceptions;

use Exception;

class SshCommandException extends Exception
{
    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly ?string $stderr = null,
    ) {
        $message = "SSH command failed with exit code {$exitCode}: {$command}";

        if ($stderr) {
            $message .= "\nSTDERR: {$stderr}";
        }

        if ($output) {
            $message .= "\nOutput: {$output}";
        }

        parent::__construct($message);
    }
}
