<?php

namespace App\Data;

readonly class KeyPair
{
    public function __construct(
        public string $privateKey,
        public string $publicKey,
    ) {}
}
