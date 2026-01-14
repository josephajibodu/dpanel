<?php

namespace App\Data;

readonly class ProviderServerStatus
{
    public function __construct(
        public string $id,
        public string $status,
        public bool $isActive,
        public ?string $ipAddress = null,
        public ?string $privateIpAddress = null,
    ) {}
}
