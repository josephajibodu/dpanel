<?php

namespace App\Exceptions;

use Exception;

class ProviderApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $provider = null,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct($message);
    }
}
