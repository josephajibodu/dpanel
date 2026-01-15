<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class SshConnectionException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
