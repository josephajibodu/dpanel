<?php

namespace App\Enums;

enum NodeVersion: string
{
    case Node20 = '20';
    case Node22 = '22';
    case Node24 = '24';

    public function label(): string
    {
        return $this->isEndOfLife()
            ? "Node.js {$this->value} (end of life)"
            : "Node.js {$this->value}";
    }

    /**
     * Whether this major version no longer receives security updates.
     */
    public function isEndOfLife(): bool
    {
        return $this === self::Node20;
    }
}
