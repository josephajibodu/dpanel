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
        parent::__construct("SSH command failed with exit code {$exitCode}: {$command}");
    }
}
