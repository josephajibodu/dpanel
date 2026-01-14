<?php

namespace App\Exceptions;

use Exception;

class SshConnectionException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
    ) {
        parent::__construct($message);
    }
}
