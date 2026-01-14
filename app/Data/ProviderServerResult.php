<?php

namespace App\Data;

readonly class ProviderServerResult
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
    ) {}
}
